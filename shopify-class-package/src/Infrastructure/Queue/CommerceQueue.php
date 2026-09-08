<?php

namespace Itmar\ShopifyClassPackage\Infrastructure\Queue;

use WP_Error;

if (! defined('ABSPATH')) exit;

/**
 * EC プロバイダー共通の永続キュー。
 *
 * 2-A では基盤だけを提供し、商品同期の既存フックは 2-B でこのキューへ切り替える。
 */
final class CommerceQueue
{
    private const DB_VERSION = '1.0.0';
    private const DB_VERSION_OPTION = 'itmar_commerce_queue_db_version';
    private const CRON_HOOK = 'itmar_commerce_process_queue';
    private const CRON_SCHEDULE = 'itmar_every_minute';
    private const LOCK_OPTION = 'itmar_commerce_queue_runner_lock';
    private const MAX_ATTEMPTS = 5;
    private const LOCK_TTL = 30 * MINUTE_IN_SECONDS;

    private static ?self $instance = null;

    /** @var array<string, callable> */
    private array $handlers = [];

    private bool $hooksRegistered = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function registerHooks(): void
    {
        if ($this->hooksRegistered) return;
        $this->hooksRegistered = true;

        add_filter('cron_schedules', [$this, 'addCronSchedule']);
        add_action('init', [$this, 'maybeInstall'], 5);
        add_action('init', [$this, 'ensureScheduled'], 20);
        add_action(self::CRON_HOOK, [$this, 'processDue']);
    }

    public function addCronSchedule(array $schedules): array
    {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = [
                'interval' => MINUTE_IN_SECONDS,
                'display'  => __('Every minute (ITMAR Commerce Queue)', 'itmaroon-ec-relate-blocks'),
            ];
        }
        return $schedules;
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL,
            direction varchar(16) NOT NULL,
            action varchar(64) NOT NULL,
            object_type varchar(32) NOT NULL DEFAULT 'product',
            post_id bigint(20) unsigned DEFAULT NULL,
            external_product_id varchar(191) NOT NULL DEFAULT '',
            dedupe_key char(64) NOT NULL,
            payload longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            locked_at datetime DEFAULT NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY due_items (status, available_at),
            KEY post_lookup (post_id),
            KEY external_lookup (provider, external_product_id(150))
        ) {$charsetCollate};";

        dbDelta($sql);
        $installedTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($installedTable === $table) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        }
    }

    public function maybeInstall(): void
    {
        if ((string) get_option(self::DB_VERSION_OPTION, '') !== self::DB_VERSION) {
            self::install();
        }
    }

    public function ensureScheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK, [], true);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_option(self::LOCK_OPTION);
    }

    public function registerHandler(string $provider, callable $handler): void
    {
        $provider = sanitize_key($provider);
        if ($provider === '') {
            throw new \InvalidArgumentException('Commerce provider is required.');
        }
        $this->handlers[$provider] = $handler;
    }

    /**
     * キューへ登録する。同じ dedupe_key は1件へ統合し、待機時刻を更新する。
     *
     * @param array{
     *   provider:string,
     *   action:string,
     *   direction?:string,
     *   object_type?:string,
     *   post_id?:int,
     *   external_product_id?:string,
     *   payload?:array,
     *   delay?:int,
     *   dedupe_key?:string
     * } $item
     * @return int|WP_Error
     */
    public function enqueue(array $item)
    {
        global $wpdb;

        $provider = sanitize_key((string) ($item['provider'] ?? ''));
        $action = sanitize_key((string) ($item['action'] ?? ''));
        $direction = sanitize_key((string) ($item['direction'] ?? 'outbound'));
        $objectType = sanitize_key((string) ($item['object_type'] ?? 'product'));
        $postId = isset($item['post_id']) ? absint($item['post_id']) : 0;
        $externalProductId = sanitize_text_field((string) ($item['external_product_id'] ?? ''));
        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];
        $delay = max(0, (int) ($item['delay'] ?? 0));

        if ($provider === '' || $action === '') {
            return new WP_Error('itmar_queue_invalid_item', 'Commerce queue provider and action are required.');
        }
        if (!in_array($direction, ['outbound', 'inbound', 'reconcile'], true)) {
            return new WP_Error('itmar_queue_invalid_direction', 'Commerce queue direction is invalid.');
        }

        $payloadJson = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return new WP_Error('itmar_queue_invalid_payload', 'Commerce queue payload could not be encoded.');
        }

        $dedupeSource = (string) ($item['dedupe_key'] ?? implode('|', [
            $provider,
            $direction,
            $action,
            $objectType,
            (string) $postId,
            $externalProductId,
        ]));
        $dedupeKey = hash('sha256', $dedupeSource);
        $now = gmdate('Y-m-d H:i:s');
        $availableAt = gmdate('Y-m-d H:i:s', time() + $delay);
        $table = self::tableName();

        // LAST_INSERT_ID(id) により、追加時も統合時も同じ方法でIDを取得できる。
        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                (provider, direction, action, object_type, post_id, external_product_id,
                 dedupe_key, payload, status, attempts, available_at, locked_at,
                 last_error, created_at, updated_at)
             VALUES (%s, %s, %s, %s, NULLIF(%d, 0), %s, %s, %s, 'pending', 0, %s, NULL, NULL, %s, %s)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                payload = VALUES(payload),
                post_id = VALUES(post_id),
                external_product_id = VALUES(external_product_id),
                status = 'pending',
                attempts = 0,
                available_at = VALUES(available_at),
                locked_at = NULL,
                last_error = NULL,
                updated_at = VALUES(updated_at)",
            $provider,
            $direction,
            $action,
            $objectType,
            $postId,
            $externalProductId,
            $dedupeKey,
            $payloadJson,
            $availableAt,
            $now,
            $now
        );
        $result = $wpdb->query($sql);
        if ($result === false) {
            return new WP_Error('itmar_queue_insert_failed', $wpdb->last_error ?: 'Commerce queue item could not be saved.');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * 実行可能な項目をロックし、登録済みプロバイダーハンドラーへ渡す。
     */
    public function processDue(): void
    {
        global $wpdb;

        $lockToken = $this->acquireRunnerLock();
        if ($lockToken === null) return;

        try {
            $table = self::tableName();
            $now = gmdate('Y-m-d H:i:s');
            $staleAt = gmdate('Y-m-d H:i:s', time() - self::LOCK_TTL);
            $runStartedAt = microtime(true);

            // PHP停止などで processing のまま残った項目を再試行へ戻す。
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'retry', locked_at = NULL, available_at = %s,
                     last_error = 'Recovered after an interrupted queue run.', updated_at = %s
                 WHERE status = 'processing' AND locked_at < %s",
                $now,
                $now,
                $staleAt
            ));

            $batchSize = (int) apply_filters('itmar_commerce_queue_batch_size', 10);
            $batchSize = max(1, min($batchSize, 100));
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT id, direction FROM {$table}
                 WHERE status IN ('pending', 'retry') AND available_at <= %s
                 ORDER BY available_at ASC, id ASC
                 LIMIT %d",
                $now,
                $batchSize
            ), ARRAY_A);

            $outboundProcessed = 0;
            $maxOutboundPerRun = max(1, (int) apply_filters('itmar_commerce_queue_max_outbound_per_run', 1));
            foreach ($items as $item) {
                $this->processOne((int) $item['id']);
                if ((string) $item['direction'] === 'outbound') {
                    $outboundProcessed++;
                    // 商品作成・更新を短時間に連続送信せず、Shopify側の負荷制限を避ける。
                    if ($outboundProcessed >= $maxOutboundPerRun) {
                        break;
                    }
                }
                // 画像アップロードを伴う商品作成は長時間になるため、次の項目を始める前に終了する。
                $timeBudget = (int) apply_filters('itmar_commerce_queue_time_budget', 20);
                if ((microtime(true) - $runStartedAt) >= max(5, $timeBudget)) {
                    break;
                }
            }

            $retentionDays = (int) apply_filters(
                'itmar_commerce_queue_history_retention_days',
                apply_filters('itmar_commerce_queue_completed_retention_days', 7)
            );
            $retentionDays = max(1, $retentionDays);
            $deleteBefore = gmdate('Y-m-d H:i:s', time() - ($retentionDays * DAY_IN_SECONDS));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE status IN ('completed', 'cancelled') AND updated_at < %s",
                $deleteBefore
            ));
        } finally {
            $this->releaseRunnerLock($lockToken);
        }
    }

    /**
     * 管理画面や診断処理用の直近履歴。
     */
    public function getRecent(int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, min($limit, 200));
        $table = self::tableName();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    public function getStatusCounts(): array
    {
        global $wpdb;

        $table = self::tableName();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A);
        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    public function countWaiting(string $provider, string $action): int
    {
        global $wpdb;

        $provider = sanitize_key($provider);
        $action = sanitize_key($action);
        if ($provider === '' || $action === '') return 0;

        $table = self::tableName();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE provider = %s AND action = %s AND status IN ('pending', 'retry')",
            $provider,
            $action
        ));
    }

    public function hasWaitingDedupeSource(string $dedupeSource): bool
    {
        global $wpdb;

        if ($dedupeSource === '') return false;
        $table = self::tableName();
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table}
             WHERE dedupe_key = %s AND status IN ('pending', 'retry') LIMIT 1",
            hash('sha256', $dedupeSource)
        ));
    }

    /**
     * より大きな集約処理で置き換えられた待機ジョブを取り消す。
     */
    public function supersedeWaiting(string $provider, string $action, string $reason): int
    {
        global $wpdb;

        $provider = sanitize_key($provider);
        $action = sanitize_key($action);
        if ($provider === '' || $action === '') return 0;

        $table = self::tableName();
        $now = gmdate('Y-m-d H:i:s');
        $reason = wp_strip_all_tags($reason);
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'cancelled', locked_at = NULL, last_error = %s, updated_at = %s
             WHERE provider = %s AND action = %s AND status IN ('pending', 'retry')",
            $reason,
            $now,
            $provider,
            $action
        ));
        return $result === false ? 0 : (int) $result;
    }

    public function retry(int $id, int $delay = 0): bool
    {
        global $wpdb;

        $table = self::tableName();
        $result = $wpdb->update(
            $table,
            [
                'status'       => 'retry',
                'attempts'     => 0,
                'available_at' => gmdate('Y-m-d H:i:s', time() + max(0, $delay)),
                'locked_at'    => null,
                'last_error'   => null,
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        return $result !== false && $result > 0;
    }

    /**
     * ローカル投稿の削除時に、まだ実行していない関連処理を取り消す。
     */
    public function cancelForPost(int $postId, string $provider = ''): int
    {
        global $wpdb;

        $postId = absint($postId);
        if ($postId === 0) return 0;

        $table = self::tableName();
        $now = gmdate('Y-m-d H:i:s');
        $whereProvider = '';
        $params = [$now, $postId];
        if ($provider !== '') {
            $whereProvider = ' AND provider = %s';
            $params[] = sanitize_key($provider);
        }

        $sql = "UPDATE {$table}
                SET status = 'cancelled', locked_at = NULL,
                    last_error = 'Cancelled because the local post was deleted.', updated_at = %s
                WHERE post_id = %d AND status IN ('pending', 'retry', 'processing'){$whereProvider}";
        $result = $wpdb->query($wpdb->prepare($sql, ...$params));
        return $result === false ? 0 : (int) $result;
    }

    private function processOne(int $id): void
    {
        global $wpdb;

        $table = self::tableName();
        $now = gmdate('Y-m-d H:i:s');
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'processing', attempts = attempts + 1, locked_at = %s, updated_at = %s
             WHERE id = %d AND status IN ('pending', 'retry') AND available_at <= %s",
            $now,
            $now,
            $id,
            $now
        ));
        if ($claimed !== 1) return;

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (!is_array($item)) return;

        try {
            $handler = $this->handlers[(string) $item['provider']] ?? null;
            if (!$handler) {
                throw new PermanentQueueException(sprintf(
                    'No commerce queue handler is registered for provider "%s".',
                    (string) $item['provider']
                ));
            }

            $payload = json_decode((string) ($item['payload'] ?? ''), true);
            $item['payload'] = is_array($payload) ? $payload : [];
            $result = $handler($item);
            if ($result instanceof WP_Error) {
                $errorData = $result->get_error_data();
                if (is_array($errorData) && array_key_exists('retryable', $errorData) && !$errorData['retryable']) {
                    throw new PermanentQueueException($result->get_error_message());
                }
                throw new \RuntimeException($result->get_error_message());
            }
            if ($result === false) {
                throw new \RuntimeException('Commerce queue handler returned failure.');
            }

            // 実行中に同じ dedupe_key が再投入された場合、その新しい pending 状態を上書きしない。
            $completed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'completed', locked_at = NULL, last_error = NULL, updated_at = %s
                 WHERE id = %d AND status = 'processing' AND locked_at = %s",
                gmdate('Y-m-d H:i:s'),
                $id,
                $now
            ));
            if ($completed === 1) {
                do_action('itmar_commerce_queue_item_completed', $item);
            }
        } catch (\Throwable $e) {
            $this->recordFailure($item, $e, $now);
        }
    }

    private function recordFailure(array $item, \Throwable $error, string $claimedAt): void
    {
        global $wpdb;

        $attempts = (int) ($item['attempts'] ?? 0);
        $permanent = $error instanceof PermanentQueueException || $attempts >= self::MAX_ATTEMPTS;
        $status = $permanent ? 'failed' : 'retry';
        $delays = [1 => MINUTE_IN_SECONDS, 2 => 5 * MINUTE_IN_SECONDS, 3 => 15 * MINUTE_IN_SECONDS];
        $delay = $delays[$attempts] ?? HOUR_IN_SECONDS;
        $now = gmdate('Y-m-d H:i:s');

        $table = self::tableName();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = %s, available_at = %s, locked_at = NULL, last_error = %s, updated_at = %s
             WHERE id = %d AND status = 'processing' AND locked_at = %s",
            $status,
            gmdate('Y-m-d H:i:s', time() + ($permanent ? 0 : $delay)),
            wp_strip_all_tags($error->getMessage()),
            $now,
            (int) $item['id'],
            $claimedAt
        ));

        if ($updated === 1) {
            do_action('itmar_commerce_queue_item_failed', $item, $error, $permanent);
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[ITMAR commerce queue id=%d provider=%s status=%s] %s',
                (int) $item['id'],
                (string) $item['provider'],
                $status,
                $error->getMessage()
            ));
        }
    }

    private function acquireRunnerLock(): ?string
    {
        $lock = get_option(self::LOCK_OPTION, null);
        if (is_array($lock) && (int) ($lock['created_at'] ?? 0) < time() - self::LOCK_TTL) {
            delete_option(self::LOCK_OPTION);
        }

        $token = wp_generate_uuid4();
        $created = add_option(
            self::LOCK_OPTION,
            ['token' => $token, 'created_at' => time()],
            '',
            false
        );
        return $created ? $token : null;
    }

    private function releaseRunnerLock(string $token): void
    {
        $lock = get_option(self::LOCK_OPTION, null);
        if (is_array($lock) && hash_equals((string) ($lock['token'] ?? ''), $token)) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'itmar_commerce_queue';
    }
}
