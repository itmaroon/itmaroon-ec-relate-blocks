<?php

namespace Itmar\ShopifyClassPackage\Interface\Admin;

use Itmar\ShopifyClassPackage\Infrastructure\Queue\CommerceQueue;
use Itmar\ShopifyClassPackage\Interface\Rest\ProductController;
use Itmar\ShopifyClassPackage\Support\ShopifyApi;
use WP_Error;

if (!defined('ABSPATH')) exit;

/**
 * Shopify商品同期の状態確認と手動操作を提供する管理画面。
 */
final class CommerceSyncPage
{
    private const PAGE_SLUG = 'itmar-commerce-sync';
    private const NOTICE_PREFIX = 'itmar_commerce_admin_notice_';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_itmar_commerce_sync_now', [$this, 'handleSyncNow']);
        add_action('admin_post_itmar_commerce_retry_queue', [$this, 'handleRetryQueue']);
    }

    public function addMenu(): void
    {
        add_management_page(
            __('EC Integration Status', 'itmaroon-ec-relate-blocks'),
            __('EC Integration Status', 'itmaroon-ec-relate-blocks'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function handleSyncNow(): void
    {
        $this->assertAdministrator();
        check_admin_referer('itmar_commerce_sync_now');

        $restoreDeleted = !empty($_POST['restore_deleted']);
        $controller = new ProductController();
        $queueId = $controller->enqueueCatalogScan(true, $restoreDeleted);

        if ($queueId instanceof WP_Error) {
            $this->setNotice('error', $queueId->get_error_message());
        } else {
            $this->setNotice(
                'success',
                $restoreDeleted
                    ? __('Synchronization was queued, including locally deleted products.', 'itmaroon-ec-relate-blocks')
                    : __('Synchronization was queued.', 'itmaroon-ec-relate-blocks')
            );
        }
        $this->redirectToPage();
    }

    public function handleRetryQueue(): void
    {
        $this->assertAdministrator();
        check_admin_referer('itmar_commerce_retry_queue');

        $queueId = isset($_POST['queue_id']) ? absint(wp_unslash($_POST['queue_id'])) : 0;
        if ($queueId === 0 || !CommerceQueue::instance()->retry($queueId)) {
            $this->setNotice('error', __('The queue item could not be retried.', 'itmaroon-ec-relate-blocks'));
        } else {
            $this->setNotice('success', __('The queue item was queued for retry.', 'itmaroon-ec-relate-blocks'));
        }
        $this->redirectToPage();
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'itmaroon-ec-relate-blocks'));
        }

        $queue = CommerceQueue::instance();
        $counts = $queue->getStatusCounts();
        $recent = $queue->getRecent(50);
        $notice = get_transient(self::NOTICE_PREFIX . get_current_user_id());
        delete_transient(self::NOTICE_PREFIX . get_current_user_id());

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('EC Integration Status', 'itmaroon-ec-relate-blocks') . '</h1>';
        echo '<p>' . esc_html__('This integration is displayed based on the Product Block settings when the block is placed in a template or post content.', 'itmaroon-ec-relate-blocks') . '</p>';

        if (is_array($notice) && !empty($notice['message'])) {
            $noticeClass = ($notice['type'] ?? '') === 'error' ? 'notice-error' : 'notice-success';
            printf(
                '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($noticeClass),
                esc_html((string) $notice['message'])
            );
        }

        $this->renderOverview($counts);
        $this->renderApiVersions();
        $this->renderProductTable();
        $this->renderSyncForm();
        $this->renderQueueTable($recent);
        echo '</div>';
    }

    private function renderApiVersions(): void
    {
        $configured = ShopifyApi::configuredVersions();
        $observed = ShopifyApi::versionStatus();
        $labels = [
            'admin' => __('Admin API', 'itmaroon-ec-relate-blocks'),
            'storefront' => __('Storefront API', 'itmaroon-ec-relate-blocks'),
            'customer_account' => __('Customer Account API', 'itmaroon-ec-relate-blocks'),
        ];

        echo '<h2>' . esc_html__('Shopify API versions', 'itmaroon-ec-relate-blocks') . '</h2>';
        echo '<p>' . esc_html__('Shopify API versions are supported for a limited period. If the configured and observed versions differ, Shopify may have substituted a supported version. Review the Shopify release notes and verify the integration before updating the configured version.', 'itmaroon-ec-relate-blocks') . '</p>';
        echo '<table class="widefat striped" style="max-width:900px">';
        echo '<thead><tr>';
        foreach ([__('API', 'itmaroon-ec-relate-blocks'), __('Configured version', 'itmaroon-ec-relate-blocks'), __('Observed version', 'itmaroon-ec-relate-blocks'), __('HTTP status', 'itmaroon-ec-relate-blocks'), __('Last checked', 'itmaroon-ec-relate-blocks'), __('Status', 'itmaroon-ec-relate-blocks')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($configured as $api => $version) {
            $item = is_array($observed[$api] ?? null) ? $observed[$api] : [];
            $actual = (string) ($item['actual'] ?? '');
            $checkedAt = (int) ($item['checked_at'] ?? 0);
            if ($actual === '') {
                $state = $checkedAt > 0
                    ? __('Version header was not returned', 'itmaroon-ec-relate-blocks')
                    : __('Not checked yet', 'itmaroon-ec-relate-blocks');
            } elseif (!empty($item['matches'])) {
                $state = __('Matched', 'itmaroon-ec-relate-blocks');
            } else {
                $state = __('Mismatch - review required', 'itmaroon-ec-relate-blocks');
            }

            printf(
                '<tr><th scope="row">%1$s</th><td><code>%2$s</code></td><td><code>%3$s</code></td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
                esc_html((string) ($labels[$api] ?? $api)),
                esc_html((string) $version),
                esc_html($actual !== '' ? $actual : '—'),
                esc_html(isset($item['http_status']) ? (string) (int) $item['http_status'] : '—'),
                esc_html($checkedAt > 0 ? $this->formatTimestamp($checkedAt) : __('Not yet', 'itmaroon-ec-relate-blocks')),
                esc_html($state)
            );
        }
        echo '</tbody></table>';
    }

    private function renderOverview(array $counts): void
    {
        $nextRun = wp_next_scheduled('itmar_commerce_process_queue');
        $postTypeSlug = (string) get_option('itmar_product_post', 'product');
        $postTypeObject = get_post_type_object($postTypeSlug);
        $postTypeName = $postTypeObject ? (string) $postTypeObject->labels->name : $postTypeSlug;
        $rows = [
            __('Shop domain', 'itmaroon-ec-relate-blocks') => (string) get_option('shopify_shop_domain', ''),
            __('Connected post type', 'itmaroon-ec-relate-blocks') => $postTypeName,
            __('Last requested', 'itmaroon-ec-relate-blocks') => $this->formatTimestamp((int) get_option('itmar_shopify_catalog_scan_requested_at', 0)),
            __('Last catalog scan', 'itmaroon-ec-relate-blocks') => $this->formatTimestamp((int) get_option('itmar_shopify_catalog_scan_completed_at', 0)),
            __('Last reconciliation', 'itmaroon-ec-relate-blocks') => $this->formatTimestamp((int) get_option('itmar_shopify_catalog_reconciled_at', 0)),
            __('Next queue run', 'itmaroon-ec-relate-blocks') => $nextRun ? $this->formatTimestamp((int) $nextRun) : __('Not scheduled', 'itmaroon-ec-relate-blocks'),
        ];

        echo '<h2>' . esc_html__('Connected commerce overview', 'itmaroon-ec-relate-blocks') . '</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        foreach ($rows as $label => $value) {
            printf(
                '<tr><th scope="row" style="width:220px">%1$s</th><td>%2$s</td></tr>',
                esc_html($label),
                esc_html($value !== '' ? $value : __('Not configured', 'itmaroon-ec-relate-blocks'))
            );
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Queue status', 'itmaroon-ec-relate-blocks') . '</h2>';
        echo '<p>' . esc_html__('A queue is a list of synchronization tasks waiting to run in the background. Product creation, connection verification, and catalog import tasks are registered here, and WP-Cron processes them in order. Temporary failures are retried automatically.', 'itmaroon-ec-relate-blocks') . '</p>';
        echo '<ul class="subsubsub" style="float:none">';
        $statuses = ['pending', 'processing', 'retry', 'failed', 'completed', 'cancelled'];
        foreach ($statuses as $status) {
            printf(
                '<li><strong>%1$s:</strong> %2$d&nbsp;&nbsp;</li>',
                esc_html(ucfirst($status)),
                (int) ($counts[$status] ?? 0)
            );
        }
        echo '</ul>';
    }

    private function renderProductTable(): void
    {
        $postType = (string) get_option('itmar_product_post', 'product');
        $posts = get_posts([
            'post_type'              => $postType,
            'post_status'            => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
            'posts_per_page'         => 100,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        echo '<hr style="margin:24px 0">';
        echo '<h2>' . esc_html__('Product synchronization status', 'itmaroon-ec-relate-blocks') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<caption class="screen-reader-text">' . esc_html__('Synchronization status for up to 100 recently modified products.', 'itmaroon-ec-relate-blocks') . '</caption>';
        echo '<thead><tr>';
        foreach ([__('Product', 'itmaroon-ec-relate-blocks'), __('WordPress status', 'itmaroon-ec-relate-blocks'), __('Shopify product ID', 'itmaroon-ec-relate-blocks'), __('Shopify state', 'itmaroon-ec-relate-blocks'), __('Sellable', 'itmaroon-ec-relate-blocks'), __('Sync status', 'itmaroon-ec-relate-blocks'), __('Last checked', 'itmaroon-ec-relate-blocks'), __('Error', 'itmaroon-ec-relate-blocks')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($posts === []) {
            echo '<tr><td colspan="8">' . esc_html__('No products were found.', 'itmaroon-ec-relate-blocks') . '</td></tr>';
        }

        foreach ($posts as $post) {
            $postId = (int) $post->ID;
            $editLink = get_edit_post_link($postId);
            $title = get_the_title($postId);
            $productCell = $editLink
                ? sprintf('<a href="%1$s">%2$s</a>', esc_url($editLink), esc_html($title !== '' ? $title : __('(no title)', 'itmaroon-ec-relate-blocks')))
                : esc_html($title);
            $statusObject = get_post_status_object((string) $post->post_status);
            $sellableMeta = get_post_meta($postId, '_itmar_shopify_sellable', true);
            $sellable = $sellableMeta === ''
                ? __('Unknown', 'itmaroon-ec-relate-blocks')
                : ((int) $sellableMeta === 1 ? __('Yes', 'itmaroon-ec-relate-blocks') : __('No', 'itmaroon-ec-relate-blocks'));
            $lastChecked = (string) get_post_meta($postId, '_itmar_shopify_cache_checked_at', true);
            if ($lastChecked === '') {
                $lastChecked = (string) get_post_meta($postId, 'itmar_shopify_last_synced_at', true);
            }

            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td><code>%4$s</code></td><td>%5$s</td><td><code>%6$s</code></td><td>%7$s</td><td>%8$s</td></tr>',
                $productCell,
                esc_html($statusObject ? $statusObject->label : (string) $post->post_status),
                esc_html((string) get_post_meta($postId, 'shopify_product_id', true)),
                esc_html((string) get_post_meta($postId, '_itmar_shopify_remote_state', true)),
                esc_html($sellable),
                esc_html((string) get_post_meta($postId, 'itmar_shopify_sync_status', true)),
                esc_html($this->formatMysqlGmt($lastChecked)),
                esc_html((string) get_post_meta($postId, 'itmar_shopify_sync_error', true))
            );
        }
        echo '</tbody></table>';
    }

    private function renderSyncForm(): void
    {
        echo '<hr style="margin:24px 0">';
        echo '<h2>' . esc_html__('Manual synchronization', 'itmaroon-ec-relate-blocks') . '</h2>';
        echo '<p>' . esc_html__('Automatic catalog synchronization is requested at approximately 15-minute intervals. Manual synchronization adds a catalog scan to the queue immediately without waiting for the next automatic request. The queued task is then processed in the background by WP-Cron.', 'itmaroon-ec-relate-blocks') . '</p>';
        echo '<p>' . esc_html__('The scan imports Shopify products that are not yet connected to this site as draft posts, updates sales information such as prices, inventory, and Shopify product status, and marks connected products that no longer exist on Shopify. Existing WordPress-specific titles, content, images, taxonomies, and publication status are preserved.', 'itmaroon-ec-relate-blocks') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="itmar_commerce_sync_now">';
        wp_nonce_field('itmar_commerce_sync_now');
        echo '<fieldset style="margin:1em 0"><legend class="screen-reader-text">' . esc_html__('Synchronization options', 'itmaroon-ec-relate-blocks') . '</legend>';
        echo '<label><input type="checkbox" name="restore_deleted" value="1"> ';
        echo esc_html__('Restore Shopify products that were permanently deleted from this WordPress site.', 'itmaroon-ec-relate-blocks');
        echo '</label></fieldset>';
        submit_button(__('Queue synchronization', 'itmaroon-ec-relate-blocks'), 'primary', 'submit', false);
        echo '</form>';
    }

    private function renderQueueTable(array $recent): void
    {
        echo '<hr style="margin:24px 0">';
        echo '<h2>' . esc_html__('Queue processing log', 'itmaroon-ec-relate-blocks') . '</h2>';
        echo '<p>' . esc_html__('The table shows the 50 most recent synchronization queue items. Completed and cancelled items are automatically deleted after seven days. Pending and retry items remain until processing finishes, and failed items remain available for review and manual retry.', 'itmaroon-ec-relate-blocks') . '</p>';
        echo '<table class="widefat striped">';
        echo '<caption class="screen-reader-text">' . esc_html__('The 50 most recent commerce synchronization queue items.', 'itmaroon-ec-relate-blocks') . '</caption>';
        echo '<thead><tr>';
        foreach ([__('ID', 'itmaroon-ec-relate-blocks'), __('Status', 'itmaroon-ec-relate-blocks'), __('Action', 'itmaroon-ec-relate-blocks'), __('Post', 'itmaroon-ec-relate-blocks'), __('Shopify product ID', 'itmaroon-ec-relate-blocks'), __('Attempts', 'itmaroon-ec-relate-blocks'), __('Updated', 'itmaroon-ec-relate-blocks'), __('Error / operation', 'itmaroon-ec-relate-blocks')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($recent === []) {
            echo '<tr><td colspan="8">' . esc_html__('No queue activity has been recorded.', 'itmaroon-ec-relate-blocks') . '</td></tr>';
        }

        foreach ($recent as $item) {
            $postId = absint($item['post_id'] ?? 0);
            $postCell = '&mdash;';
            if ($postId > 0) {
                $editLink = get_edit_post_link($postId);
                $postCell = $editLink
                    ? sprintf('<a href="%1$s">#%2$d</a>', esc_url($editLink), $postId)
                    : '#' . $postId;
            }

            $status = sanitize_key((string) ($item['status'] ?? ''));
            $operation = esc_html((string) ($item['last_error'] ?? ''));
            if ($status === 'failed') {
                ob_start();
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px">';
                echo '<input type="hidden" name="action" value="itmar_commerce_retry_queue">';
                echo '<input type="hidden" name="queue_id" value="' . (int) ($item['id'] ?? 0) . '">';
                wp_nonce_field('itmar_commerce_retry_queue');
                submit_button(__('Retry', 'itmaroon-ec-relate-blocks'), 'secondary small', 'submit', false);
                echo '</form>';
                $operation .= (string) ob_get_clean();
            }

            printf(
                '<tr><td>%1$d</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$d</td><td>%7$s</td><td>%8$s</td></tr>',
                (int) ($item['id'] ?? 0),
                esc_html($status),
                esc_html((string) ($item['action'] ?? '')),
                $postCell,
                esc_html((string) ($item['external_product_id'] ?? '')),
                (int) ($item['attempts'] ?? 0),
                esc_html($this->formatMysqlGmt((string) ($item['updated_at'] ?? ''))),
                $operation
            );
        }
        echo '</tbody></table>';
    }

    private function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) return __('Not yet', 'itmaroon-ec-relate-blocks');
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function formatMysqlGmt(string $date): string
    {
        if ($date === '' || $date === '0000-00-00 00:00:00') return '';
        return get_date_from_gmt($date, get_option('date_format') . ' ' . get_option('time_format'));
    }

    private function assertAdministrator(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to perform this action.', 'itmaroon-ec-relate-blocks'),
                '',
                ['response' => 403]
            );
        }
    }

    private function setNotice(string $type, string $message): void
    {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), [
            'type' => $type,
            'message' => wp_strip_all_tags($message),
        ], MINUTE_IN_SECONDS);
    }

    private function redirectToPage(): void
    {
        wp_safe_redirect(admin_url('tools.php?page=' . self::PAGE_SLUG));
        exit;
    }
}
