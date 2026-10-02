<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;
use Itmar\ShopifyClassPackage\Infrastructure\Queue\CommerceQueue;
use Itmar\ShopifyClassPackage\Infrastructure\Queue\PermanentQueueException;
use Itmar\ShopifyClassPackage\Support\ShopifyApi;

if (! defined('ABSPATH')) exit;

final class ProductController extends BaseController
{
    private const CATALOG_SCAN_REQUESTED_OPTION = 'itmar_shopify_catalog_scan_requested_at';
    private const CATALOG_SCAN_COMPLETED_OPTION = 'itmar_shopify_catalog_scan_completed_at';
    private const CATALOG_SCAN_INTERVAL = 15 * MINUTE_IN_SECONDS;
    private const UNLINKED_BACKFILL_VERSION_OPTION = 'itmar_shopify_unlinked_backfill_version';
    private const UNLINKED_BACKFILL_CURSOR_OPTION = 'itmar_shopify_unlinked_backfill_cursor';
    private const UNLINKED_BACKFILL_VERSION = 1;

    private bool $suppressQueueing = false;
    /** @var int[] */
    private array $deletingPostIds = [];

    /**
     * REST のルート登録（商品情報の取得）
     */
    public function registerRest(): void
    {
        // 公開APIにするなら publicAccess()、RESTノンス必須にするなら gate(null,'wp_rest',false)
        register_rest_route($this->ns(), '/get-product', [[
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'getProductInfo'],
            'permission_callback' => $this->public_gate(), //未ログインの公開ゲート
            'args' => [
                'fields'  => ['required' => true, 'type' => 'array'],
                'itemNum' => ['required' => false, 'type' => 'integer'],
            ],
        ]]);

        register_rest_route($this->ns(), '/get-collections', [[
            'methods'             => WP_REST_Server::READABLE, // GET
            'callback'            => [$this, 'getUsedProductCategories'],
            'permission_callback' => $this->public_gate(), //未ログインの公開ゲート,
        ]]);

        register_rest_route($this->ns(), '/products/sync', [[
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'requestCatalogSync'],
            'permission_callback' => $this->gate('manage_options', 'wp_rest', true),
        ]]);
    }

    /**
     * WP の各種フック登録（保存/削除/cron）
     */
    public function registerWpHooks(): void
    {
        $this->registerProductConnectionMeta();
        $this->clearLegacySyncCron();

        // 投稿削除前：未実行キューを取り消す（Shopify商品自体は削除しない）
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 1);

        // 投稿・メタ・分類の変更を安定待ち付きキューへ集約する。
        add_action('save_post', [$this, 'onSavePost'], 20, 2);
        add_action('added_post_meta', [$this, 'onPostMetaChanged'], 20, 4);
        add_action('updated_post_meta', [$this, 'onPostMetaChanged'], 20, 4);
        add_action('deleted_post_meta', [$this, 'onPostMetaChanged'], 20, 4);
        add_action('set_object_terms', [$this, 'onTermsChanged'], 20, 6);
        // ゴミ箱からの復元完了後に Shopify の関連付けを再確認する。
        add_action('untrashed_post', [$this, 'onUntrashedPost'], 10, 2);
        // 公開API用トークンCookieの発行を指示
        add_action('wp', [$this, 'issue_public_cookie_on_front'], 1);

        // Webhook に依存せず、一定間隔で Shopify カタログの確認をキューへ投入する。
        $this->maybeEnqueueCatalogScan();
        // この機能の導入前から存在する未接続商品も、少量ずつ新規登録キューへ移す。
        $this->maybeBackfillUnlinkedProducts();
    }

    public function registerQueueHandler(): void
    {
        CommerceQueue::instance()->registerHandler('shopify', [$this, 'processQueueItem']);
    }
    // 公開API用トークンCookieの発行
    public function issue_public_cookie_on_front(): void
    {
        if (is_admin()) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        if (wp_doing_ajax()) return;

        $cookie_name = 'itmar_public_api_token';

        // 既に有効なら何もしない
        if (!empty($_COOKIE[$cookie_name])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
            if (get_transient('itmar_pub_' . hash('sha256', $token))) {
                return;
            }
        }

        $token  = wp_generate_password(32, false, false);
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        if (is_string($domain) && $domain !== '') {
            $args['domain'] = $domain;
        }

        setcookie($cookie_name, $token, [
            'expires'  => time() + 30 * MINUTE_IN_SECONDS,
            'path'     => '/', // ★REST(/wp-json)にも送る
            'domain'   => $domain ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[$cookie_name] = $token;
        set_transient('itmar_pub_' . hash('sha256', $token), 1, 30 * MINUTE_IN_SECONDS);
    }

    // =========================
    // REST: 商品一覧（Storefront API, GraphQL）
    // =========================

    public function getProductInfo(WP_REST_Request $request)
    {
        try {
            $fieldTemplates = [
                'title'           => 'title',
                'handle'          => 'handle',
                'description'     => 'description',
                'descriptionHtml' => 'descriptionHtml',
                'vendor'          => 'vendor',
                'productType'     => 'productType',
                'tags'            => 'tags',
                'onlineStoreUrl'  => 'onlineStoreUrl',
                'createdAt'       => 'createdAt',
                'updatedAt'       => 'updatedAt',
                'medias'          => 'media(first: 250) {
                edges {
                    node {
                        mediaContentType
                        ... on MediaImage { image { url altText width height } }
                        ... on Video { alt sources { url format mimeType width height } }
                    }
                }
            }',
                'variants'        => 'variants(first: 10) {
                edges {
                    node {
                        id
                        title
                        availableForSale
                        quantityAvailable
                        price { amount currencyCode }
                        compareAtPrice { amount currencyCode }
                    }
                }
            }',
            ];

            $shopDomain    = (string) get_option('shopify_shop_domain');
            $storefrontTk  = (string) get_option('shopify_storefront_token');
            $adminToken    = (string) get_option('shopify_admin_token');

            if ($shopDomain === '' || $storefrontTk === '' || $adminToken === '') {
                return $this->fail(
                    new WP_Error('config_missing', 'Shopify config missing (shop_domain / storefront_token / admin_token).', ['status' => 500]),
                    500
                );
            }

            //選択された商品情報フィールド
            $fields = $request->get_param('fields');
            if (!is_array($fields) || empty($fields)) {
                return $this->fail(new WP_Error('invalid_fields', 'fields パラメータが必要です。', ['status' => 400]), 400);
            }

            //商品の絞り込みキーワード
            $searchTextRaw = $request->get_param('searchKeyWord');
            $searchText = is_string($searchTextRaw) ? trim(wp_unslash($searchTextRaw)) : '';

            //登録された期間
            $updatedFromRaw = $request->get_param('updatedFrom');
            $updatedFrom = is_string($updatedFromRaw) ? trim(wp_unslash($updatedFromRaw)) : '';

            $updatedToRaw = $request->get_param('updatedTo');
            $updatedTo = is_string($updatedToRaw) ? trim(wp_unslash($updatedToRaw)) : '';

            //選択された商品カテゴリ
            $categoryIds = (array) $request->get_param('categoryIds');

            //表示する商品数
            $perPage = (int) ($request->get_param('itemNum') ?? 10);
            if ($perPage <= 0)  $perPage = 10;
            if ($perPage > 250) $perPage = 250;

            // targetPage（= page）…フロントの pageNum
            $targetPage = (int) ($request->get_param('page') ?? 0);
            if ($targetPage < 0) $targetPage = 0;

            // anchorPage（= anchorPage）…フロントで計算したやつ
            $anchorPageRaw = $request->get_param('anchorPage');
            $anchorPage = is_numeric($anchorPageRaw) ? (int) $anchorPageRaw : 0;
            if ($anchorPage < 0) $anchorPage = 0;

            // anchorCursor（= anchorCursor）…フロントの cursorByPage[anchorPage]
            $anchorCursorRaw = $request->get_param('anchorCursor');
            $anchorCursor = is_string($anchorCursorRaw) ? trim(wp_unslash($anchorCursorRaw)) : null;
            if ($anchorCursor === '') $anchorCursor = null;
            //総数をとるかどうかのフラグ
            $includeCount = (bool) ($request->get_param('includeCount') ?? false);

            // 安全ガード：anchor は target を超えられない（前進しかできないため）
            if ($anchorPage > $targetPage) {
                $anchorPage = 0;
                $anchorCursor = null;
            }

            // Storefront 用の selection set を組み立て
            $selected = array_values(array_filter($fields, fn($f) => isset($fieldTemplates[$f])));
            // variants は常に追加
            if (!in_array('variants', $selected, true)) {
                $selected[] = 'variants';
            }
            // image/images が要求されたら medias を追加
            if (in_array('image', $fields, true) || in_array('images', $fields, true)) {
                if (!in_array('medias', $selected, true)) $selected[] = 'medias';
            }
            //フィールド指定文字列
            $graphqlFieldStr = implode("\n", array_map(fn($f) => $fieldTemplates[$f], $selected));

            // ★ Admin側の検索クエリ文字列（category_id で絞り込む）
            $adminQueryStr = $this->build_admin_products_query($categoryIds, $searchText, $updatedFrom, $updatedTo);

            // ★ フィルタ条件込みで afterCursor を解決（Adminで解決する必要あり）
            $afterCursor = $this->resolve_after_cursor_for_page_admin(
                $shopDomain,
                $adminToken,
                $targetPage,
                $perPage,
                $anchorPage,
                $anchorCursor,
                $adminQueryStr
            );
            if (is_wp_error($afterCursor)) return $this->fail($afterCursor, 500);

            // ① Admin: ID + cursor だけ取る（フィルタ反映）
            $adminPage = $this->admin_fetch_ids_page_and_count(
                $shopDomain,
                $adminToken,
                $perPage,
                ($afterCursor && $afterCursor !== '') ? $afterCursor : null,
                $adminQueryStr,
                $includeCount
            );
            if (is_wp_error($adminPage)) return $this->fail($adminPage, 500);

            $edges = $adminPage['edges'] ?? [];
            $pageInfo = $adminPage['pageInfo'] ?? ['hasNextPage' => false, 'endCursor' => null];

            if (empty($edges)) {
                return $this->ok([
                    'products' => [],
                    'pageInfo' => [
                        'hasNextPage' => (bool)($pageInfo['hasNextPage'] ?? false),
                        'endCursor'   => $pageInfo['endCursor'] ?? null,
                    ],
                ]);
            }

            $productIds = [];
            foreach ($edges as $e) {
                $pid = $e['node']['id'] ?? '';
                if ($pid) $productIds[] = $pid;
            }

            // ② Storefront: nodes(ids: ...) で詳細取得（あなたの $graphqlFieldStr をそのまま使う）:contentReference[oaicite:2]{index=2}
            $nodes = $this->storefront_fetch_products_by_ids(
                $shopDomain,
                $storefrontTk,
                $productIds,
                $graphqlFieldStr
            );
            if (is_wp_error($nodes)) return $this->fail($nodes, 500);

            // id => node の辞書
            $byId = [];
            foreach ($nodes as $n) {
                if (is_array($n) && !empty($n['id'])) $byId[$n['id']] = $n;
            }

            // Adminの並び順で products を並べる（null は除外）
            $ordered = [];
            foreach ($productIds as $pid) {
                if (isset($byId[$pid])) $ordered[] = $byId[$pid];
            }

            $resp = [
                'products' => $ordered,
                'pageInfo' => [
                    'hasNextPage' => (bool)($pageInfo['hasNextPage'] ?? false),
                    'endCursor'   => $pageInfo['endCursor'] ?? null,
                ],
            ];

            if ($includeCount) {
                $c = $adminPage['count'] ?? ['count' => 0, 'precision' => 'EXACT'];
                $resp['count'] = [
                    'count'     => (int)$c['count'],
                    'precision' => (string)$c['precision'], // EXACT / AT_LEAST
                    'display'   => ((string)$c['precision'] === 'EXACT') ? (string)(int)$c['count'] : '10,000+',
                ];
            }

            return $this->ok($resp);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }

    private function normalize_taxonomy_category_id($id)
    {
        $id = (string) $id;
        if ($id === '') return '';

        // gid://shopify/TaxonomyCategory/sg-... → sg-...
        if (strpos($id, 'gid://') === 0) {
            $parts = explode('/', $id);
            return (string) end($parts);
        }
        return $id;
    }

    private function escape_search_value($v): string
    {
        // まず改行などを潰す
        $v = preg_replace("/[\\r\\n\\t]+/u", " ", $v);
        $v = trim($v);
        // search syntax の値として安全側（" を使うので最低限エスケープ）
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace('"', '\"', $v);
        return $v;
    }

    
    private function build_admin_products_query(
        array $categoryIds,
        string $searchText,
        ?string $updatedFrom = null,
        ?string $updatedTo = null
    ): string {
        $norm = [];

        foreach ($categoryIds as $cid) {
            $cid = $this->normalize_taxonomy_category_id($cid);

            if ($cid === '') continue;
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $cid)) continue;

            $norm[] = $cid;
        }

        /*
         * 出品中（ACTIVE）の商品を対象にする。
         *
         * 以前は published_status:published だった。これは**オンラインストアチャネル**
         * への公開状況を見る条件で、ヘッドレス構成（表示は自前のサイト、可視性は
         * Headless チャネルが決める）とは別のチャネルを見ていた。そのため、
         * Headless に正しく公開していてもオンラインストアに公開していない商品は
         * 一覧に出てこなかった。
         *
         * 実際に表示できるかどうかは、このあとの Storefront の問い合わせ
         * （Headless チャネルのトークンを使う）が決める。そこで取れなかった商品は
         * 除外されるので、ここでは出品中かどうかだけを見ればよい。
         */
        $qParts = [
            'status:active',
        ];

        if (!empty($norm)) {
            $or = [];

            foreach ($norm as $cid) {
                $or[] = 'category_id:"'
                    . $this->escape_search_value($cid)
                    . '"';
            }

            $qParts[] = '(' . implode(' OR ', $or) . ')';
        }

        if ($searchText !== '') {
            $qParts[] = '"'
                . $this->escape_search_value($searchText)
                . '"';
        }

        if ($updatedFrom !== null && $updatedFrom !== '') {
            $qParts[] = 'updated_at:>="'
                . $this->escape_search_value($updatedFrom)
                . '"';
        }

        if ($updatedTo !== null && $updatedTo !== '') {
            $qParts[] = 'updated_at:<="'
                . $this->escape_search_value($updatedTo)
                . '"';
        }

        return implode(' AND ', $qParts);
    }

    private function admin_fetch_ids_page_and_count($shopDomain, $adminToken, $first, $after, $queryStr, $includeCount)
    {
        $shopDomain = sanitize_text_field((string) $shopDomain);
        $adminToken = sanitize_text_field((string) $adminToken);
        $endpoint = esc_url_raw(ShopifyApi::adminUrl($shopDomain, 'graphql.json'));

        $gql = implode("\n", [
            'query ProductsIdsAndCount($first: Int!, $after: String, $query: String, $limit: Int) {',
            '  products(first: $first, after: $after, sortKey: CREATED_AT, reverse: true, query: $query) {',
            '    pageInfo { hasNextPage endCursor }',
            '    edges { cursor node { id } }',
            '  }',
            // ★ includeCount=false のときもクエリ自体は書けますが、
            //   Shopify側の計算負荷を減らすなら、false時は productsCount を入れない構成も可。
            '  productsCount(query: $query, limit: $limit) @include(if: true) {',
            '    count',
            '    precision',
            '  }',
            '}',
        ]);

        // ※GraphQLの @include は Boolean 変数が必要になります。
        // もし複雑にしたくなければ、includeCount=falseのときは productsCount 自体をクエリ文字列から外す方が簡単です。
        // ここでは「簡単さ優先」で “常に取得” の実装にして、呼び出し側で使う/使わないを決める形にします。

        $payload = [
            'query' => $gql,
            'variables' => [
                'first' => (int)$first,
                'after' => $after,
                'query' => $queryStr ?: null,
                'limit' => null,
            ],
        ];

        $resp = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type'           => 'application/json; charset=utf-8',
                'X-Shopify-Access-Token' => $adminToken,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['errors'])) {
            return new WP_Error('shopify_admin_gql_error', 'Admin GraphQL error', ['errors' => $body['errors']]);
        }

        $productsConn = $body['data']['products'] ?? null;
        if (!is_array($productsConn)) {
            return new WP_Error('shopify_admin_gql_invalid', 'Admin response invalid', ['raw' => $body]);
        }

        $countObj = $body['data']['productsCount'] ?? null;

        return [
            'edges'    => $productsConn['edges'] ?? [],
            'pageInfo' => $productsConn['pageInfo'] ?? ['hasNextPage' => false, 'endCursor' => null],
            'count'    => [
                'count'     => (int)($countObj['count'] ?? 0),
                'precision' => (string)($countObj['precision'] ?? 'EXACT'),
            ],
        ];
    }

    //AdminAPI使用上のゲート（permission_callback 用）
    protected function public_gate(): callable
    {
        return function (\WP_REST_Request $request) {
            $cookie_name = 'itmar_public_api_token';
            $token = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';

            if ($token === '') {
                return new \WP_Error('itmar_rest_forbidden', 'Missing public api token cookie.', ['status' => 403]);
            }

            $key = 'itmar_pub_' . hash('sha256', $token);
            if (!get_transient($key)) {
                return new \WP_Error('itmar_rest_forbidden', 'Public api token expired.', ['status' => 403]);
            }

            // 簡易レート制限：IPあたり1分N回
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
            $rl_key = 'itmar_rl_pub_' . hash('sha256', $ip);
            $count = (int) get_transient($rl_key);
            if ($count > 60) {
                return new \WP_Error('itmar_rest_too_many', 'Too many requests.', ['status' => 429]);
            }
            set_transient($rl_key, $count + 1, MINUTE_IN_SECONDS);

            return true;
        };
    }

    private function storefront_fetch_products_by_ids($shopDomain, $storefrontTk, array $ids, $graphqlFieldStr)
    {
        $shopDomain   = sanitize_text_field((string) $shopDomain);
        $storefrontTk = sanitize_text_field((string) $storefrontTk);

        if (empty($ids)) return [];

        $endpoint = esc_url_raw(ShopifyApi::storefrontUrl($shopDomain));

        $gql =
            'query Nodes($ids: [ID!]!) {' . "\n" .
            '  nodes(ids: $ids) {' . "\n" .
            '    ... on Product {' . "\n" .
            '      id' . "\n" .
            $graphqlFieldStr . "\n" .
            '    }' . "\n" .
            '  }' . "\n" .
            '}';

        $payload = [
            'query' => $gql,
            'variables' => [
                'ids' => array_values($ids),
            ],
        ];

        $resp = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type'                      => 'application/json; charset=utf-8',
                'X-Shopify-Storefront-Access-Token' => $storefrontTk,
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['errors'])) {
            return new WP_Error('shopify_storefront_gql_error', 'Storefront GraphQL error', ['errors' => $body['errors']]);
        }

        return $body['data']['nodes'] ?? [];
    }

    private function resolve_after_cursor_for_page_admin($shopDomain, $adminToken, $targetPage, $perPage, $anchorPage, $anchorCursor, $adminQueryStr)
    {
        if ($targetPage <= 0) return null;

        // ★ ここはあなたのフロントが持っている cursor の意味に依存します。
        // 多くの実装では「anchorCursor は anchorPage を取得したときの endCursor」なので、
        // 次に取得できるのは anchorPage+1 です。
        $cursor = $anchorCursor;
        $pageToFetch = ($cursor === null) ? 0 : ($anchorPage + 1);

        // targetPage の直前ページまで進めて、その endCursor を返す
        while ($pageToFetch <= $targetPage) {
            $page = $this->admin_fetch_ids_page_and_count(
                $shopDomain,
                $adminToken,
                $perPage,
                $cursor,
                $adminQueryStr,
                false
            );
            if (is_wp_error($page)) return $page;

            $pi = $page['pageInfo'] ?? [];
            $cursor = $pi['endCursor'] ?? null;

            // 次ページが無いのに進もうとしたら打ち切り（= その先のページは存在しない）
            if (empty($pi['hasNextPage'])) break;

            $pageToFetch++;
        }

        return $cursor;
    }


    // =========================
    // 商品コレクションを取得
    // =========================

    public function getUsedProductCategories()
    {

        $admin_token = get_option('shopify_admin_token');
        $shop_domain = get_option('shopify_shop_domain');

        if (empty($admin_token) || empty($shop_domain)) {
            return new WP_REST_Response(array('error' => 'Shopify settings missing.'), 400);
        }

        // 公開ルートならキャッシュ推奨（レート制限・負荷対策）
        $cache_key = 'itmar_shopify_used_categories_v1';
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        // ★ 日本語マップを先に読み込む（ループ中に何度も取らない）
        $locale = determine_locale();
        $is_ja = (strpos($locale, 'ja') === 0);
        $ja_map = $is_ja ? $this->get_taxonomy_ja_map() : array();

        // Product.category (TaxonomyCategory) を取得して集計する
        $gql = implode("\n", array(
            'query ProductsWithCategory($first: Int!, $after: String, $query: String) {',
            '  products(first: $first, after: $after, query: $query) {',
            '    edges {',
            '      cursor',
            '      node {',
            '        id',
            '        category {',
            '          id',
            '          fullName',
            '        }',
            '      }',
            '    }',
            '    pageInfo { hasNextPage endCursor }',
            '  }',
            '}',
        ));

        // 必要なら対象を絞る（例：active のみ）
        // Shopify search syntax を使います
        $product_query = 'status:active';

        $endpoint = esc_url_raw(ShopifyApi::adminUrl($shop_domain, 'graphql.json'));

        $after = null;
        $map = array(); // category_id => ['id'=>..., 'fullName'=>..., 'count'=>...]

        // 最大 10,000 商品くらいまで想定（250 * 40 = 10,000）
        for ($i = 0; $i < 40; $i++) {

            $payload = array(
                'query' => $gql,
                'variables' => array(
                    'first' => 250,
                    'after' => $after,
                    'query' => $product_query,
                ),
            );

            $resp = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Content-Type'           => 'application/json; charset=utf-8',
                    'X-Shopify-Access-Token' => $admin_token,
                ),
                'body'    => wp_json_encode($payload),
                'timeout' => 20,
            ));

            if (is_wp_error($resp)) {
                return new WP_REST_Response(array('error' => $resp->get_error_message()), 500);
            }

            $code = wp_remote_retrieve_response_code($resp);
            $raw  = wp_remote_retrieve_body($resp);

            if ($code < 200 || $code >= 300) {
                return new WP_REST_Response(array('error' => 'Shopify request failed.', 'status' => $code, 'body' => $raw), 500);
            }

            $body = json_decode($raw, true);
            if (!is_array($body)) {
                return new WP_REST_Response(array('error' => 'Invalid JSON from Shopify.'), 500);
            }
            if (!empty($body['errors'])) {
                return new WP_REST_Response(array('error' => $body['errors']), 500);
            }

            $conn = $body['data']['products'] ?? null;
            if (empty($conn['edges'])) break;

            foreach ($conn['edges'] as $edge) {
                $cat = $edge['node']['category'] ?? null;
                if (empty($cat) || empty($cat['id'])) continue; // カテゴリ未設定は除外

                $cid = (string) $cat['id'];

                // WordPressのロケールによってGIDで日本語に置換
                if ($is_ja) {
                    $fullName = isset($ja_map[$cid]) ? (string)$ja_map[$cid] : (string)($cat['fullName'] ?? '');
                } else {
                    $fullName = (string)($cat['fullName'] ?? '');
                }


                if (!isset($map[$cid])) {
                    $map[$cid] = array(
                        'id' => $cid,
                        'fullName' => $fullName,
                        'count' => 0,
                    );
                }
                $map[$cid]['count']++;
            }

            $has_next = !empty($conn['pageInfo']['hasNextPage']);
            if (!$has_next) break;

            $after = $conn['pageInfo']['endCursor'] ?? null;
            if (empty($after)) break;
        }

        $result = array_values($map);

        // 件数降順で並べたい場合
        usort($result, function ($a, $b) {
            return (int)$b['count'] <=> (int)$a['count'];
        });

        // 30分キャッシュ（公開ルートなら長めが安全）
        set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);

        return $result;
    }

    //日本語カテゴリ変換関数
    private function get_taxonomy_ja_map()
    {
        $cache_key = 'itmar_taxonomy_ja_map_v1';
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        // 公式 taxonomy（日本語）
        // ※本番運用では「main」よりも tag / release を固定するのがおすすめ
        $url = 'https://raw.githubusercontent.com/Shopify/product-taxonomy/main/dist/ja/categories.txt';

        $resp = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($resp)) {
            return array();
        }

        $text = wp_remote_retrieve_body($resp);
        if (!is_string($text) || $text === '') {
            return array();
        }

        $lines = preg_split("/\r\n|\n|\r/", $text);
        $map = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue; // コメント行など
            }

            // 形式は「{GID} : {日本語の階層表記...}」の想定
            // 例: gid://shopify/TaxonomyCategory/... : ペット・ペット用品 > ...
            $parts = explode(' : ', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $gid  = trim($parts[0]);
            $name = trim($parts[1]);

            if ($gid !== '' && $name !== '') {
                $map[$gid] = $name;
            }
        }

        // 1日キャッシュ（taxonomy は頻繁に変わらない）
        set_transient($cache_key, $map, DAY_IN_SECONDS);

        return $map;
    }




    // =========================
    // WP Hooks: 削除/保存/同期
    // =========================

    private function productPostType(): string
    {
        $postType = (string) get_option('itmar_product_post', 'product');
        return $postType !== '' ? $postType : 'product';
    }

    private function markSyncStatus(int $postId, string $status, string $error = ''): void
    {
        update_post_meta($postId, 'itmar_shopify_sync_status', $status);
        if ($status === 'success') {
            update_post_meta($postId, 'itmar_shopify_last_synced_at', current_time('mysql', true));
        }

        if ($error === '') {
            delete_post_meta($postId, 'itmar_shopify_sync_error');
        } else {
            update_post_meta($postId, 'itmar_shopify_sync_error', wp_strip_all_tags($error));
        }
    }

    public function registerProductConnectionMeta(): void
    {
        $args = [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
            'show_in_rest'      => true,
            'auth_callback'     => static fn(): bool => current_user_can('edit_posts'),
        ];
        register_post_meta($this->productPostType(), 'shopify_product_id', $args);
        register_post_meta($this->productPostType(), 'shopify_variant_id', $args);

        // Shopify から同期した価格・在庫の写しを、読み取り専用で REST に公開する。
        // query-blocks などは REST の meta に出ているキーを表示用フィールドとして選べる。
        // 正本は Shopify なので、REST からの書き換えは受け付けない。
        $cacheArgs = [
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => '__return_false',
        ];
        register_post_meta($this->productPostType(), '_itmar_shopify_cache_price', $cacheArgs + ['type' => 'string', 'default' => '']);
        register_post_meta($this->productPostType(), '_itmar_shopify_cache_compare_at_price', $cacheArgs + ['type' => 'string', 'default' => '']);
        register_post_meta($this->productPostType(), '_itmar_shopify_cache_currency_code', $cacheArgs + ['type' => 'string', 'default' => '']);
        register_post_meta($this->productPostType(), '_itmar_shopify_cache_inventory_quantity', $cacheArgs + ['type' => 'integer', 'default' => 0]);
    }

    public function clearLegacySyncCron(): void
    {
        if (get_option('itmar_commerce_legacy_sync_cron_cleared', false)) return;

        wp_clear_scheduled_hook('itmar_shopify_sync_cron');
        update_option('itmar_commerce_legacy_sync_cron_cleared', 1, false);
    }

    /**
     * 管理者が直ちに Shopify カタログ確認を要求する REST ハンドラー。
     */
    public function requestCatalogSync(WP_REST_Request $request): WP_REST_Response
    {
        $queueId = $this->enqueueCatalogScan(
            true,
            (bool) $request->get_param('restore_deleted')
        );
        if ($queueId instanceof WP_Error) {
            return $this->fail($queueId, 500);
        }

        return $this->ok([
            'queue_id' => $queueId,
            'status'   => 'queued',
        ], 202);
    }

    /**
     * ページアクセスまたは WP-Cron 起動時に、期限が来ていれば次のスキャンを予約する。
     */
    public function maybeEnqueueCatalogScan(): void
    {
        if ((string) get_option('shopify_shop_domain', '') === '' || (string) get_option('shopify_admin_token', '') === '') {
            return;
        }

        $interval = (int) apply_filters('itmar_shopify_catalog_scan_interval', self::CATALOG_SCAN_INTERVAL);
        $interval = max(MINUTE_IN_SECONDS, $interval);
        $lastRequested = (int) get_option(self::CATALOG_SCAN_REQUESTED_OPTION, 0);
        if ($lastRequested > time() - $interval) {
            return;
        }

        $this->enqueueCatalogScan(false);
    }

    /**
     * Shopify 商品一覧の先頭ページを受信キューへ投入する。
     *
     * @return int|WP_Error
     */
    public function enqueueCatalogScan(bool $force = false, bool $restoreDeleted = false, int $delay = 0)
    {
        $shopDomain = strtolower(trim((string) get_option('shopify_shop_domain', '')));
        $adminToken = (string) get_option('shopify_admin_token', '');
        if ($shopDomain === '' || $adminToken === '') {
            return new WP_Error(
                'itmar_shopify_missing_credentials',
                'Shopify credentials are not configured.',
                ['retryable' => false]
            );
        }

        $queueId = CommerceQueue::instance()->enqueue([
            'provider'            => 'shopify',
            'direction'           => 'inbound',
            'action'              => 'scan_catalog',
            'object_type'         => 'product',
            'external_product_id' => '',
            'payload'             => [
                'shop_domain' => $shopDomain,
                'cursor'      => null,
                'started_at'  => gmdate('c'),
                'forced'      => $force,
                'restore_deleted' => $restoreDeleted,
            ],
            'delay'               => max(0, $delay),
            'dedupe_key'          => 'shopify|scan_catalog|' . $shopDomain,
        ]);

        if (!($queueId instanceof WP_Error)) {
            update_option(self::CATALOG_SCAN_REQUESTED_OPTION, time(), false);
        }
        return $queueId;
    }

    private function enqueueProductSync(int $postId): void
    {
        if (
            $this->suppressQueueing ||
            in_array($postId, $this->deletingPostIds, true) ||
            get_post_type($postId) !== $this->productPostType()
        ) return;

        if (get_post_status($postId) !== 'publish') {
            CommerceQueue::instance()->cancelForPost($postId, 'shopify');
            $this->markSyncStatus($postId, 'local_only');
            return;
        }

        $productId = trim((string) get_post_meta($postId, 'shopify_product_id', true));
        $isNewProduct = $productId === '';
        $action = $isNewProduct ? 'create_product' : 'verify_connection';
        $delay = (int) apply_filters('itmar_commerce_product_settle_delay', MINUTE_IN_SECONDS, $postId);
        $queued = CommerceQueue::instance()->enqueue([
            'provider'            => 'shopify',
            'direction'           => $isNewProduct ? 'outbound' : 'reconcile',
            'action'              => $action,
            'object_type'         => 'product',
            'post_id'             => $postId,
            'external_product_id' => $productId,
            'payload'             => ['post_modified_gmt' => (string) get_post_field('post_modified_gmt', $postId)],
            'delay'               => max(0, $delay),
            'dedupe_key'          => 'shopify|' . $action . '|post|' . $postId,
        ]);

        if ($queued instanceof WP_Error) {
            $this->markSyncStatus($postId, 'error', $queued->get_error_message());
            return;
        }

        // 新規作成は投稿単位で必ず実行する。カタログ走査への集約は既存商品の照合だけに適用する。
        if ($isNewProduct) {
            $this->markSyncStatus($postId, 'pending');
            return;
        }

        $queue = CommerceQueue::instance();
        $threshold = (int) apply_filters('itmar_commerce_bulk_verification_threshold', 20);
        $threshold = max(2, $threshold);
        $shopDomain = strtolower(trim((string) get_option('shopify_shop_domain', '')));
        if (
            $queue->hasWaitingDedupeSource('shopify|scan_catalog|' . $shopDomain) ||
            $queue->countWaiting('shopify', 'verify_connection') >= $threshold
        ) {
            $settleDelay = (int) apply_filters(
                'itmar_commerce_bulk_settle_delay',
                MINUTE_IN_SECONDS
            );
            $scan = $this->enqueueCatalogScan(true, false, max(0, $settleDelay));
            if (!($scan instanceof WP_Error)) {
                $queue->supersedeWaiting(
                    'shopify',
                    'verify_connection',
                    'Superseded by a debounced Shopify catalog scan.'
                );
            }
        }
        $this->markSyncStatus($postId, 'pending');
    }

    /**
     * 導入前から存在する公開済み・未接続の商品を、1リクエスト100件までキューへ登録する。
     * カーソルを保持するため、大量の商品があっても同じ先頭100件を繰り返さない。
     */
    private function maybeBackfillUnlinkedProducts(): void
    {
        if ((int) get_option(self::UNLINKED_BACKFILL_VERSION_OPTION, 0) >= self::UNLINKED_BACKFILL_VERSION) {
            return;
        }
        if ((string) get_option('shopify_shop_domain', '') === '' || (string) get_option('shopify_admin_token', '') === '') {
            return;
        }

        global $wpdb;
        $cursor = max(0, (int) get_option(self::UNLINKED_BACKFILL_CURSOR_OPTION, 0));
        $postType = $this->productPostType();
        $postIds = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND p.ID > %d
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID
                     AND pm.meta_key = 'shopify_product_id'
                     AND pm.meta_value <> ''
               )
             ORDER BY p.ID ASC
             LIMIT 100",
            $postType,
            $cursor
        ));

        foreach ($postIds as $postId) {
            $this->enqueueProductSync((int) $postId);
            $cursor = max($cursor, (int) $postId);
        }

        if (count($postIds) < 100) {
            update_option(self::UNLINKED_BACKFILL_VERSION_OPTION, self::UNLINKED_BACKFILL_VERSION, false);
            delete_option(self::UNLINKED_BACKFILL_CURSOR_OPTION);
        } else {
            update_option(self::UNLINKED_BACKFILL_CURSOR_OPTION, $cursor, false);
        }
    }

    /**
     * added/updated_post_meta ではメタID、deleted_post_meta ではメタID配列が渡される。
     * 第1引数は利用しないため、WordPress の両方の形式を受け入れる。
     */
    public function onPostMetaChanged($metaIds, int $postId, string $metaKey, $metaValue): void
    {
        if ($this->suppressQueueing) return;

        // Post Migration 等で関連IDが明示的に再投入された場合は、ローカル削除の除外を解除する。
        if ($metaKey === 'shopify_product_id') {
            $productId = (string) get_post_meta($postId, 'shopify_product_id', true);
            if ($productId !== '') {
                $domain = (string) get_post_meta($postId, '_itmar_shopify_shop_domain', true);
                if ($domain === '') $domain = (string) get_option('shopify_shop_domain', '');
                $this->clearProductSuppression($productId, $domain);
            }
        }

        $ignoredKeys = [
            'itmar_shopify_sync_status',
            'itmar_shopify_sync_error',
            'itmar_shopify_last_synced_at',
            '_itmar_shopify_cache_title',
            '_itmar_shopify_cache_status',
            '_itmar_shopify_cache_updated_at',
            '_itmar_shopify_cache_price',
            '_itmar_shopify_cache_compare_at_price',
            '_itmar_shopify_cache_sku',
            '_itmar_shopify_cache_inventory_quantity',
            '_itmar_shopify_cache_image_url',
            '_itmar_shopify_cache_checked_at',
            '_itmar_shopify_creation_started_at',
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
        ];
        if (in_array($metaKey, $ignoredKeys, true)) return;

        $this->enqueueProductSync($postId);
    }

    public function onTermsChanged(
        int $objectId,
        $terms,
        array $termTaxonomyIds,
        string $taxonomy,
        bool $append,
        array $oldTermTaxonomyIds
    ): void {
        $this->enqueueProductSync($objectId);
    }

    /**
     * CommerceQueue から呼び出される Shopify 商品処理。
     *
     * @return true|WP_Error
     */
    public function processQueueItem(array $item)
    {
        $action = (string) ($item['action'] ?? '');
        if ($action === 'scan_catalog') {
            return $this->processCatalogScan($item);
        }
        if ($action === 'import_product') {
            return $this->processProductImport($item);
        }
        if ($action === 'finalize_catalog_scan') {
            return $this->finalizeCatalogScan($item);
        }
        if ($action === 'create_product') {
            $postId = absint($item['post_id'] ?? 0);
            if ($postId === 0 || !get_post($postId) || get_post_type($postId) !== $this->productPostType()) {
                return true;
            }
            if (get_post_status($postId) !== 'publish') {
                $this->markSyncStatus($postId, 'local_only');
                return true;
            }

            $productId = trim((string) get_post_meta($postId, 'shopify_product_id', true));
            $creationStarted = (string) get_post_meta($postId, '_itmar_shopify_creation_started_at', true);
            // 初回実行前に別経路からIDが入った場合は、既存商品を上書きせず照合へ切り替える。
            // 再試行時のIDは前回の作成成功後に保存したものなので、在庫・画像処理から安全に再開する。
            if ($productId !== '' && $creationStarted === '' && (int) ($item['attempts'] ?? 0) <= 1) {
                $this->enqueueProductSync($postId);
                return true;
            }
            if ($creationStarted === '') {
                update_post_meta($postId, '_itmar_shopify_creation_started_at', current_time('mysql', true));
            }
            return $this->syncProductFromPost($postId);
        }
        if ($action !== 'verify_connection') {
            throw new PermanentQueueException('Unsupported Shopify queue action: ' . $action);
        }

        $postId = absint($item['post_id'] ?? 0);
        if ($postId === 0 || !get_post($postId) || get_post_type($postId) !== $this->productPostType()) {
            return true;
        }
        if (get_post_status($postId) !== 'publish') {
            $this->markSyncStatus($postId, 'local_only');
            return true;
        }

        $productId = (string) get_post_meta($postId, 'shopify_product_id', true);
        if ($productId === '') {
            $this->markSyncStatus($postId, 'unlinked');
            return true;
        }

        $shopDomain = (string) get_option('shopify_shop_domain', '');
        $adminToken = (string) get_option('shopify_admin_token', '');
        if ($shopDomain === '' || $adminToken === '') {
            $message = 'Shopify credentials are not configured.';
            $this->markSyncStatus($postId, 'error', $message);
            return new WP_Error('itmar_shopify_missing_credentials', $message, ['retryable' => false]);
        }

        try {
            $body = $this->shopifyRequest(
                ShopifyApi::adminUrl($shopDomain, "products/{$productId}.json?fields=id,title,status,updated_at,image,variants"),
                [
                    'method'  => 'GET',
                    'headers' => ['X-Shopify-Access-Token' => $adminToken],
                    'timeout' => 20,
                ],
                'Shopify product connection verification'
            );
            $product = is_array($body['product'] ?? null) ? $body['product'] : [];
            if ((string) ($product['id'] ?? '') !== $productId) {
                throw new PermanentQueueException('The linked Shopify product could not be identified.');
            }

            $variant = is_array($product['variants'][0] ?? null) ? $product['variants'][0] : [];
            $image = is_array($product['image'] ?? null) ? $product['image'] : [];
            $this->suppressQueueing = true;
            try {
                if (!empty($variant['id'])) {
                    update_post_meta($postId, 'shopify_variant_id', (string) $variant['id']);
                }
                update_post_meta($postId, '_itmar_shopify_cache_title', (string) ($product['title'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_cache_status', (string) ($product['status'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_cache_updated_at', (string) ($product['updated_at'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_cache_price', (string) ($variant['price'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_cache_compare_at_price', (string) ($variant['compare_at_price'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_cache_sku', (string) ($variant['sku'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_cache_inventory_quantity', (string) ($variant['inventory_quantity'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_cache_image_url', (string) ($image['src'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_cache_checked_at', current_time('mysql', true));
                update_post_meta($postId, '_itmar_shopify_shop_domain', strtolower(trim($shopDomain)));
                $remoteState = sanitize_key((string) ($product['status'] ?? ''));
                update_post_meta($postId, '_itmar_shopify_remote_state', $remoteState);
                update_post_meta($postId, '_itmar_shopify_sellable', $remoteState === 'active' ? 1 : 0);
                $this->markSyncStatus($postId, 'success');
            } finally {
                $this->suppressQueueing = false;
            }
            return true;
        } catch (PermanentQueueException $e) {
            $this->markSyncStatus($postId, 'error', $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            if (preg_match('/HTTP 404\b/', $e->getMessage())) {
                $this->suppressQueueing = true;
                try {
                    update_post_meta($postId, '_itmar_shopify_remote_state', 'missing');
                    update_post_meta($postId, '_itmar_shopify_sellable', 0);
                    update_post_meta($postId, '_itmar_shopify_cache_status', 'missing');
                    $this->markSyncStatus($postId, 'remote_missing');
                } finally {
                    $this->suppressQueueing = false;
                }
                return true;
            }
            $this->markSyncStatus($postId, 'error', $e->getMessage());
            if (preg_match('/HTTP (401|403)\b/', $e->getMessage())) {
                return new WP_Error('itmar_shopify_connection_invalid', $e->getMessage(), ['retryable' => false]);
            }
            return new WP_Error('itmar_shopify_connection_failed', $e->getMessage(), ['retryable' => true]);
        }
    }

    /**
     * Shopify Admin GraphQL API から商品を1ページ取得し、商品単位の受信処理へ分割する。
     *
     * @return true|WP_Error
     */
    private function processCatalogScan(array $item)
    {
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $queuedDomain = strtolower(trim((string) ($payload['shop_domain'] ?? '')));
        $currentDomain = strtolower(trim((string) get_option('shopify_shop_domain', '')));
        if ($queuedDomain === '' || $queuedDomain !== $currentDomain) {
            // 設定変更前の古いジョブは、新しいショップへ適用しない。
            return true;
        }
        if ((string) get_option('shopify_admin_token', '') === '') {
            return new WP_Error(
                'itmar_shopify_missing_credentials',
                'Shopify credentials are not configured.',
                ['retryable' => false]
            );
        }

        $query = <<<'GRAPHQL'
query ItmarCatalogProducts($first: Int!, $after: String) {
  shop { currencyCode }
  products(first: $first, after: $after, sortKey: ID) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      legacyResourceId
      title
      handle
      descriptionHtml
      status
      vendor
      productType
      tags
      updatedAt
      featuredMedia {
        ... on MediaImage { image { url altText } }
      }
      variants(first: 10) {
        nodes {
          id
          legacyResourceId
          title
          sku
          price
          compareAtPrice
          inventoryQuantity
        }
      }
    }
  }
}
GRAPHQL;

        try {
            $data = $this->gql($query, [
                'first' => 50,
                'after' => !empty($payload['cursor']) ? (string) $payload['cursor'] : null,
            ]);
        } catch (\Throwable $e) {
            $retryable = !preg_match('/HTTP (401|403)\b/', $e->getMessage());
            return new WP_Error('itmar_shopify_catalog_scan_failed', $e->getMessage(), ['retryable' => $retryable]);
        }

        $connection = is_array($data['products'] ?? null) ? $data['products'] : [];
        $products = is_array($connection['nodes'] ?? null) ? $connection['nodes'] : [];
        $currencyCode = sanitize_text_field((string) ($data['shop']['currencyCode'] ?? ''));
        $startedAt = sanitize_text_field((string) ($payload['started_at'] ?? gmdate('c')));

        foreach ($products as $product) {
            if (!is_array($product)) continue;
            $productId = $this->shopifyLegacyId($product['id'] ?? '', $product['legacyResourceId'] ?? '');
            if ($productId === '') continue;

            $normalized = $this->normalizeShopifyProduct($product, $currencyCode);
            $this->markProductSeen($productId, $queuedDomain, $startedAt);
            $queued = CommerceQueue::instance()->enqueue([
                'provider'            => 'shopify',
                'direction'           => 'inbound',
                'action'              => 'import_product',
                'object_type'         => 'product',
                'external_product_id' => $productId,
                'payload'             => [
                    'shop_domain' => $queuedDomain,
                    'scan_started_at' => $startedAt,
                    'restore_deleted' => !empty($payload['restore_deleted']),
                    'product' => $normalized,
                ],
                'delay'               => 0,
                'dedupe_key'          => 'shopify|import_product|' . $queuedDomain . '|' . $productId,
            ]);
            if ($queued instanceof WP_Error) {
                return $queued;
            }
        }

        $pageInfo = is_array($connection['pageInfo'] ?? null) ? $connection['pageInfo'] : [];
        if (!empty($pageInfo['hasNextPage']) && !empty($pageInfo['endCursor'])) {
            $nextPage = CommerceQueue::instance()->enqueue([
                'provider'            => 'shopify',
                'direction'           => 'inbound',
                'action'              => 'scan_catalog',
                'object_type'         => 'product',
                'external_product_id' => '',
                'payload'             => [
                    'shop_domain' => $queuedDomain,
                    'cursor'      => (string) $pageInfo['endCursor'],
                    'started_at'  => $startedAt,
                    'restore_deleted' => !empty($payload['restore_deleted']),
                ],
                'delay'               => 0,
                'dedupe_key'          => 'shopify|scan_catalog|' . $queuedDomain,
            ]);
            if ($nextPage instanceof WP_Error) {
                return $nextPage;
            }
        } else {
            $finalize = CommerceQueue::instance()->enqueue([
                'provider'            => 'shopify',
                'direction'           => 'reconcile',
                'action'              => 'finalize_catalog_scan',
                'object_type'         => 'product',
                'external_product_id' => '',
                'payload'             => [
                    'shop_domain' => $queuedDomain,
                    'scan_started_at' => $startedAt,
                ],
                'delay'               => 0,
                'dedupe_key'          => 'shopify|finalize_catalog_scan|' . $queuedDomain,
            ]);
            if ($finalize instanceof WP_Error) {
                return $finalize;
            }
            update_option(self::CATALOG_SCAN_COMPLETED_OPTION, time(), false);
        }

        return true;
    }

    private function shopifyLegacyId($gid, $legacyId): string
    {
        $legacyId = trim((string) $legacyId);
        if ($legacyId !== '') return $legacyId;

        $gid = trim((string) $gid);
        if (preg_match('#/(\d+)$#', $gid, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function normalizeShopifyProduct(array $product, string $currencyCode): array
    {
        $variants = [];
        $variantNodes = $product['variants']['nodes'] ?? [];
        foreach (is_array($variantNodes) ? $variantNodes : [] as $variant) {
            if (!is_array($variant)) continue;
            $variantId = $this->shopifyLegacyId($variant['id'] ?? '', $variant['legacyResourceId'] ?? '');
            if ($variantId === '') continue;
            $variants[] = [
                'id'                 => $variantId,
                'gid'                => sanitize_text_field((string) ($variant['id'] ?? '')),
                'title'              => sanitize_text_field((string) ($variant['title'] ?? '')),
                'sku'                => sanitize_text_field((string) ($variant['sku'] ?? '')),
                'price'              => (string) ($variant['price'] ?? ''),
                'compare_at_price'   => (string) ($variant['compareAtPrice'] ?? ''),
                'inventory_quantity' => isset($variant['inventoryQuantity']) ? (int) $variant['inventoryQuantity'] : null,
            ];
        }

        $featuredImage = $product['featuredMedia']['image'] ?? [];
        return [
            'id'               => $this->shopifyLegacyId($product['id'] ?? '', $product['legacyResourceId'] ?? ''),
            'gid'              => sanitize_text_field((string) ($product['id'] ?? '')),
            'title'            => sanitize_text_field((string) ($product['title'] ?? '')),
            'handle'           => sanitize_title((string) ($product['handle'] ?? '')),
            'description_html' => wp_kses_post((string) ($product['descriptionHtml'] ?? '')),
            'status'           => sanitize_key((string) ($product['status'] ?? '')),
            'vendor'           => sanitize_text_field((string) ($product['vendor'] ?? '')),
            'product_type'     => sanitize_text_field((string) ($product['productType'] ?? '')),
            'tags'             => array_values(array_map('sanitize_text_field', is_array($product['tags'] ?? null) ? $product['tags'] : [])),
            'updated_at'       => sanitize_text_field((string) ($product['updatedAt'] ?? '')),
            'currency_code'    => $currencyCode,
            'image_url'        => esc_url_raw((string) ($featuredImage['url'] ?? '')),
            'image_alt'        => sanitize_text_field((string) ($featuredImage['altText'] ?? '')),
            'variants'         => $variants,
        ];
    }

    /**
     * 一覧取得時点で既存投稿に走査印を付ける。
     * 商品単位の取込が再試行になっても、存在する商品を missing と誤判定しないための印。
     */
    private function markProductSeen(string $productId, string $shopDomain, string $scanStartedAt): void
    {
        $postId = $this->findPostByShopifyProductId($productId, $shopDomain);
        if ($postId === 0) return;

        $this->suppressQueueing = true;
        try {
            update_post_meta($postId, '_itmar_shopify_shop_domain', $shopDomain);
            update_post_meta($postId, '_itmar_shopify_last_scan_started_at', $scanStartedAt);
        } finally {
            $this->suppressQueueing = false;
        }
    }

    /**
     * 全ページ取得が完了した走査だけを確定し、Shopifyに存在しなかった関連投稿を記録する。
     * 投稿の削除・ゴミ箱移動・公開状態変更は行わない。
     *
     * @return true|WP_Error
     */
    private function finalizeCatalogScan(array $item)
    {
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $queuedDomain = strtolower(trim((string) ($payload['shop_domain'] ?? '')));
        $currentDomain = strtolower(trim((string) get_option('shopify_shop_domain', '')));
        $scanStartedAt = trim((string) ($payload['scan_started_at'] ?? ''));
        if ($queuedDomain === '' || $queuedDomain !== $currentDomain || $scanStartedAt === '') {
            return true;
        }

        $postIds = get_posts([
            'post_type'              => $this->productPostType(),
            'post_status'            => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'meta_query'             => [
                'relation' => 'OR',
                [
                    'key'   => '_itmar_shopify_shop_domain',
                    'value' => $queuedDomain,
                ],
                [
                    'key'     => '_itmar_shopify_shop_domain',
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        $this->suppressQueueing = true;
        try {
            foreach ($postIds as $postId) {
                $postId = (int) $postId;
                if ((string) get_post_meta($postId, 'shopify_product_id', true) === '') continue;
                if ((string) get_post_meta($postId, '_itmar_shopify_last_scan_started_at', true) === $scanStartedAt) continue;

                update_post_meta($postId, '_itmar_shopify_shop_domain', $queuedDomain);
                update_post_meta($postId, '_itmar_shopify_remote_state', 'missing');
                update_post_meta($postId, '_itmar_shopify_sellable', 0);
                update_post_meta($postId, '_itmar_shopify_cache_status', 'missing');
                $this->markSyncStatus($postId, 'remote_missing');
                do_action('itmar_shopify_product_missing', $postId, $queuedDomain);
            }
        } finally {
            $this->suppressQueueing = false;
        }

        update_option('itmar_shopify_catalog_reconciled_at', time(), false);
        return true;
    }

    /**
     * 商品スナップショットを WordPress へ反映する。
     * 既存投稿のサイト固有コンテンツは変更しない。
     *
     * @return true|WP_Error
     */
    private function processProductImport(array $item)
    {
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $queuedDomain = strtolower(trim((string) ($payload['shop_domain'] ?? '')));
        $currentDomain = strtolower(trim((string) get_option('shopify_shop_domain', '')));
        if ($queuedDomain === '' || $queuedDomain !== $currentDomain) {
            return true;
        }

        $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];
        $productId = trim((string) ($product['id'] ?? $item['external_product_id'] ?? ''));
        if ($productId === '') {
            return new WP_Error('itmar_shopify_invalid_product', 'Shopify product ID is missing.', ['retryable' => false]);
        }

        if (empty($payload['restore_deleted']) && $this->isProductSuppressed($productId, $queuedDomain)) {
            return true;
        }

        $postId = $this->findPostByShopifyProductId($productId, $queuedDomain);
        $created = false;
        $this->suppressQueueing = true;
        try {
            if ($postId === 0) {
                $title = trim((string) ($product['title'] ?? ''));
                if ($title === '') $title = 'Shopify Product ' . $productId;

                $postStatus = (string) apply_filters(
                    'itmar_shopify_import_post_status',
                    'draft',
                    $product,
                    $this->productPostType()
                );
                if (!in_array($postStatus, ['draft', 'pending', 'private', 'publish'], true)) {
                    $postStatus = 'draft';
                }

                $inserted = wp_insert_post(wp_slash([
                    'post_type'    => $this->productPostType(),
                    'post_status'  => $postStatus,
                    'post_title'   => $title,
                    'post_name'    => sanitize_title((string) ($product['handle'] ?? '')),
                    'post_content' => (string) ($product['description_html'] ?? ''),
                    'post_excerpt' => wp_trim_words(wp_strip_all_tags((string) ($product['description_html'] ?? '')), 55),
                ]), true);
                if ($inserted instanceof WP_Error) {
                    return new WP_Error('itmar_shopify_post_insert_failed', $inserted->get_error_message(), ['retryable' => true]);
                }
                $postId = (int) $inserted;
                $created = true;
            }

            $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
            $firstVariant = is_array($variants[0] ?? null) ? $variants[0] : [];
            update_post_meta($postId, 'shopify_product_id', $productId);
            update_post_meta($postId, '_itmar_shopify_shop_domain', $queuedDomain);
            if (!empty($firstVariant['id'])) {
                update_post_meta($postId, 'shopify_variant_id', (string) $firstVariant['id']);
            }

            // Shopify を正として扱う販売情報。既存の本文・タイトル・画像・分類は触らない。
            update_post_meta($postId, 'prices_sales_price', (string) ($firstVariant['price'] ?? ''));
            update_post_meta($postId, 'prices_list_price', (string) ($firstVariant['compare_at_price'] ?? ''));
            update_post_meta($postId, 'quantity', isset($firstVariant['inventory_quantity']) ? (int) $firstVariant['inventory_quantity'] : 0);
            update_post_meta($postId, '_itmar_shopify_cache_title', (string) ($product['title'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_status', (string) ($product['status'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_updated_at', (string) ($product['updated_at'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_price', (string) ($firstVariant['price'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_compare_at_price', (string) ($firstVariant['compare_at_price'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_sku', (string) ($firstVariant['sku'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_inventory_quantity', isset($firstVariant['inventory_quantity']) ? (int) $firstVariant['inventory_quantity'] : 0);
            update_post_meta($postId, '_itmar_shopify_cache_currency_code', (string) ($product['currency_code'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_image_url', (string) ($product['image_url'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_image_alt', (string) ($product['image_alt'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_vendor', (string) ($product['vendor'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_product_type', (string) ($product['product_type'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_tags', is_array($product['tags'] ?? null) ? $product['tags'] : []);
            update_post_meta($postId, '_itmar_shopify_cache_variants', $variants);
            update_post_meta($postId, '_itmar_shopify_cache_checked_at', current_time('mysql', true));
            update_post_meta($postId, '_itmar_shopify_imported_at', current_time('mysql', true));
            update_post_meta($postId, '_itmar_shopify_last_scan_started_at', (string) ($payload['scan_started_at'] ?? ''));
            $remoteState = sanitize_key((string) ($product['status'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_remote_state', $remoteState);
            update_post_meta($postId, '_itmar_shopify_sellable', $remoteState === 'active' ? 1 : 0);
            $this->markSyncStatus($postId, get_post_status($postId) === 'publish' ? 'success' : 'local_only');
            $this->clearProductSuppression($productId, $queuedDomain);
        } finally {
            $this->suppressQueueing = false;
        }

        do_action('itmar_shopify_product_imported', $postId, $product, $created);
        return true;
    }

    private function findPostByShopifyProductId(string $productId, string $shopDomain): int
    {
        $posts = get_posts([
            'post_type'              => $this->productPostType(),
            'post_status'            => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
            'posts_per_page'         => -1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'meta_key'               => 'shopify_product_id',
            'meta_value'             => $productId,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        if (!$posts) return 0;

        // 同じIDが複数ある場合は現在のショップドメインが一致する投稿を優先する。
        foreach ($posts as $postId) {
            if ((string) get_post_meta((int) $postId, '_itmar_shopify_shop_domain', true) === $shopDomain) {
                return (int) $postId;
            }
        }
        return (int) $posts[0];
    }

    private function suppressionOptionName(string $shopDomain): string
    {
        return 'itmar_shopify_suppressed_' . substr(hash('sha256', strtolower(trim($shopDomain))), 0, 24);
    }

    private function suppressProduct(string $productId, string $shopDomain): void
    {
        $productId = trim($productId);
        $shopDomain = strtolower(trim($shopDomain));
        if ($productId === '' || $shopDomain === '') return;

        $optionName = $this->suppressionOptionName($shopDomain);
        $suppressed = get_option($optionName, []);
        if (!is_array($suppressed)) $suppressed = [];
        $suppressed[$productId] = time();

        if (get_option($optionName, null) === null) {
            add_option($optionName, $suppressed, '', false);
        } else {
            update_option($optionName, $suppressed, false);
        }
    }

    private function isProductSuppressed(string $productId, string $shopDomain): bool
    {
        $suppressed = get_option($this->suppressionOptionName($shopDomain), []);
        return is_array($suppressed) && array_key_exists($productId, $suppressed);
    }

    private function clearProductSuppression(string $productId, string $shopDomain): void
    {
        $productId = trim($productId);
        $shopDomain = strtolower(trim($shopDomain));
        if ($productId === '' || $shopDomain === '') return;

        $optionName = $this->suppressionOptionName($shopDomain);
        $suppressed = get_option($optionName, []);
        if (!is_array($suppressed) || !array_key_exists($productId, $suppressed)) return;

        unset($suppressed[$productId]);
        if ($suppressed === []) {
            delete_option($optionName);
        } else {
            update_option($optionName, $suppressed, false);
        }
    }

    /**
     * Shopify REST API の応答を検証し、失敗時は例外に統一する。
     */
    private function shopifyRequest(string $url, array $args, string $operation): array
    {
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new \RuntimeException($operation . ': ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $detail = trim(wp_strip_all_tags((string) $rawBody));
            throw new \RuntimeException(sprintf(
                '%s failed (HTTP %d)%s',
                $operation,
                $statusCode,
                $detail !== '' ? ': ' . $detail : ''
            ));
        }

        if (trim((string) $rawBody) === '') {
            return [];
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            throw new \RuntimeException($operation . ': Shopify returned an invalid JSON response.');
        }
        if (!empty($body['errors'])) {
            throw new \RuntimeException($operation . ': ' . wp_json_encode($body['errors'], JSON_UNESCAPED_UNICODE));
        }

        return $body;
    }

    public function onBeforeDeletePost(int $postId): void
    {
        $productPostType = $this->productPostType();
        if (get_post_type($postId) !== $productPostType) return;
        $this->deletingPostIds[] = $postId;
        $productId = (string) get_post_meta($postId, 'shopify_product_id', true);
        $shopDomain = (string) get_post_meta($postId, '_itmar_shopify_shop_domain', true);
        if ($shopDomain === '') $shopDomain = (string) get_option('shopify_shop_domain', '');
        $this->suppressProduct($productId, $shopDomain);
        CommerceQueue::instance()->cancelForPost($postId, 'shopify');
    }

    public function onSavePost(int $postId, \WP_Post $post): void
    {
        $productPostType = $this->productPostType();
        if ($post->post_type !== $productPostType) return;

        // 自動保存・リビジョンは無視
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ('revision' === get_post_type($postId)) return;

        $this->enqueueProductSync($postId);
    }

    public function onUntrashedPost(int $postId, string $previousStatus = ''): void
    {
        $productPostType = $this->productPostType();
        if (get_post_type($postId) !== $productPostType) return;

        $this->enqueueProductSync($postId);
    }


    /**
     * WP → Shopify の商品作成処理。
     * 作成後の補助処理に失敗して再試行された場合だけ、保存済みIDの商品を更新して処理を完遂する。
     *
     * @return true|WP_Error
     */
    public function syncProductFromPost(int $postId)
    {
        $productPostType = $this->productPostType();
        if (get_post_type($postId) !== $productPostType) return true;

        // 予約後に下書き・ゴミ箱へ変更された投稿を、Shopify で再公開しない。
        if (get_post_status($postId) !== 'publish') return true;

        $this->markSyncStatus($postId, 'pending');
        $previousSuppression = $this->suppressQueueing;
        $this->suppressQueueing = true;

        try {
            // 投稿情報
            $title         = get_the_title($postId);
            $description   = get_the_excerpt($postId);

            // 価格・定価・在庫の正本は Shopify（processProductImport がその写しを保存する）。
            // 更新時は送らない。送ると、保存のたびに古い写しで Shopify の値を上書きしてしまう
            // （以前は空を '0' とみなして送っていたため、0円・在庫0へ戻ることもあった）。
            // 新規作成時だけ、WordPress 側に値があれば初期値として送る。
            $priceMeta    = (string) get_post_meta($postId, 'prices_sales_price', true);
            $regularMeta  = (string) get_post_meta($postId, 'prices_list_price', true);
            $quantityMeta = (string) get_post_meta($postId, 'quantity', true);
            $hasPrice     = $priceMeta !== '';
            $hasRegular   = $regularMeta !== '';
            $hasQuantity  = $quantityMeta !== '';

            // Shopify 接続情報
            $shopDomain  = (string) get_option('shopify_shop_domain');
            $adminToken  = (string) get_option('shopify_admin_token');
            $channelName = (string) get_option('shopify_channel_name');
            if ($adminToken === '' || $shopDomain === '') {
                throw new \RuntimeException('Shopify credentials are not configured.');
            }

            $productId = (string) get_post_meta($postId, 'shopify_product_id', true);
            $isCreate  = $productId === '';

            $productData = [
                'product' => [
                    'title'     => $title,
                    'body_html' => $description,
                ],
            ];

            // 更新時はバリエーションを送らない（Shopify 側の価格・在庫設定は変更されない）。
            if ($isCreate) {
                $variant = [
                    'option1'              => 'Default Title',
                    'inventory_management' => 'shopify',
                    'inventory_policy'     => 'deny',
                ];
                if ($hasPrice) {
                    $variant['price'] = $priceMeta;
                }
                // Shopify は通常価格が販売価格以下の場合の compare_at_price を受け付けない。
                if ($hasPrice && $hasRegular && (float) $regularMeta > (float) $priceMeta) {
                    $variant['compare_at_price'] = $regularMeta;
                }
                $productData['product']['variants'] = [$variant];

                // 価格が無いまま作ると0円で販売チャネルに並んでしまうので、下書きで作る。
                // Shopify で価格を入れて公開すれば、以後の更新でステータスは変更しない。
                if (!$hasPrice) {
                    $productData['product']['status'] = 'draft';
                }
            }

            if (!$isCreate) {
                $storedVariantId = (string) get_post_meta($postId, 'shopify_variant_id', true);
                if ($storedVariantId === '') {
                    $existingProduct = $this->shopifyRequest(
                        ShopifyApi::adminUrl($shopDomain, "products/{$productId}.json?fields=id,variants"),
                        [
                            'method'  => 'GET',
                            'headers' => ['X-Shopify-Access-Token' => $adminToken],
                            'timeout' => 20,
                        ],
                        'Shopify product linkage retrieval'
                    );
                    $storedVariantId = (string) ($existingProduct['product']['variants'][0]['id'] ?? '');
                    if ($storedVariantId !== '') {
                        update_post_meta($postId, 'shopify_variant_id', $storedVariantId);
                    }
                }
                if ($storedVariantId !== '' && isset($productData['product']['variants'])) {
                    $productData['product']['variants'][0]['id'] = $storedVariantId;
                }
                $productData['product']['id'] = $productId;
                $body = $this->shopifyRequest(
                    ShopifyApi::adminUrl($shopDomain, "products/{$productId}.json"),
                    [
                        'method'  => 'PUT',
                        'headers' => [
                            'X-Shopify-Access-Token' => $adminToken,
                            'Content-Type'           => 'application/json',
                        ],
                        'body'    => wp_json_encode($productData),
                        'timeout' => 20,
                    ],
                    'Shopify product update'
                );
            } else {
                $body = $this->shopifyRequest(
                    ShopifyApi::adminUrl($shopDomain, 'products.json'),
                    [
                        'method'  => 'POST',
                        'headers' => [
                            'X-Shopify-Access-Token' => $adminToken,
                            'Content-Type'           => 'application/json',
                        ],
                        'body'    => wp_json_encode($productData),
                        'timeout' => 20,
                    ],
                    'Shopify product creation'
                );

                $productId = (string) ($body['product']['id'] ?? '');
                if ($productId === '') {
                    throw new \RuntimeException('Shopify product creation returned no product ID.');
                }
                // 作成に成功した時点で保存し、後続処理の失敗による重複作成を防ぐ。
                update_post_meta($postId, 'shopify_product_id', $productId);
            }

            $responseVariantId = (string) ($body['product']['variants'][0]['id'] ?? '');
            if ($responseVariantId !== '') {
                update_post_meta($postId, 'shopify_variant_id', $responseVariantId);
            }
            $variantId = (string) get_post_meta($postId, 'shopify_variant_id', true);

            // 公開状態は WordPress の公開に従って販売中にする。ただし Shopify 側の価格が
            // まだ0円（価格未入力で下書き作成した直後など）なら販売中にしない。
            $shopifyPrice = (float) ($body['product']['variants'][0]['price'] ?? 0);
            $this->publishToChannel((int) $productId, $channelName, $shopifyPrice > 0);
            // 在庫も新規作成時の初期値としてだけ送る。
            if ($variantId !== '' && $isCreate && $hasQuantity) {
                $this->syncInventory($postId, $shopDomain, $adminToken, $variantId, (int) $quantityMeta);
            }
            $this->syncImages($postId, $shopDomain, $adminToken, $productId);

            // 控え（_itmar_shopify_cache_*）は Shopify の応答の値だけで作る。
            // WordPress 側の値で埋めると、正本の Shopify とずれた表示になる。
            $responseVariant = $body['product']['variants'][0] ?? [];
            $remoteState = sanitize_key((string) ($body['product']['status'] ?? 'active')) ?: 'active';
            update_post_meta($postId, '_itmar_shopify_shop_domain', strtolower(trim($shopDomain)));
            update_post_meta($postId, '_itmar_shopify_cache_title', (string) ($body['product']['title'] ?? $title));
            update_post_meta($postId, '_itmar_shopify_cache_status', $remoteState);
            update_post_meta($postId, '_itmar_shopify_cache_price', (string) ($responseVariant['price'] ?? ''));
            update_post_meta($postId, '_itmar_shopify_cache_compare_at_price', (string) ($responseVariant['compare_at_price'] ?? ''));
            update_post_meta(
                $postId,
                '_itmar_shopify_cache_inventory_quantity',
                ($isCreate && $hasQuantity) ? max((int) $quantityMeta, 0) : max((int) ($responseVariant['inventory_quantity'] ?? 0), 0)
            );
            update_post_meta($postId, '_itmar_shopify_cache_checked_at', current_time('mysql', true));
            update_post_meta($postId, '_itmar_shopify_remote_state', $remoteState);
            update_post_meta($postId, '_itmar_shopify_sellable', $remoteState === 'active' ? 1 : 0);
            delete_post_meta($postId, '_itmar_shopify_creation_started_at');
            $this->markSyncStatus($postId, 'success');
            return true;
        } catch (\Throwable $e) {
            $this->markSyncStatus($postId, 'error', $e->getMessage());
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[Shopify sync post_id=%d] %s', $postId, $e->getMessage()));
            }
            $retryable = !preg_match('/HTTP (401|403)\b/', $e->getMessage())
                && $e->getMessage() !== 'Shopify credentials are not configured.';
            return new WP_Error(
                'itmar_shopify_product_sync_failed',
                $e->getMessage(),
                ['retryable' => $retryable]
            );
        } finally {
            $this->suppressQueueing = $previousSuppression;
        }
    }

    private function syncInventory(
        int $postId,
        string $shopDomain,
        string $adminToken,
        string $variantId,
        int $quantity
    ): void {
        $variantBody = $this->shopifyRequest(
            ShopifyApi::adminUrl($shopDomain, "variants/{$variantId}.json"),
            [
                'method'  => 'GET',
                'headers' => ['X-Shopify-Access-Token' => $adminToken],
                'timeout' => 20,
            ],
            'Shopify variant retrieval'
        );
        $inventoryItemId = (string) ($variantBody['variant']['inventory_item_id'] ?? '');
        if ($inventoryItemId === '') {
            throw new \RuntimeException('Shopify variant returned no inventory item ID.');
        }

        $locationBody = $this->shopifyRequest(
            ShopifyApi::adminUrl($shopDomain, 'locations.json'),
            [
                'method'  => 'GET',
                'headers' => ['X-Shopify-Access-Token' => $adminToken],
                'timeout' => 20,
            ],
            'Shopify location retrieval'
        );
        $locations = $locationBody['locations'] ?? [];
        if (!is_array($locations) || empty($locations[0]['id'])) {
            throw new \RuntimeException('No Shopify inventory location is available.');
        }

        // 他ロケーションの在庫を 0 にせず、選択した1ロケーションだけを更新する。
        $defaultLocationId = (string) $locations[0]['id'];
        $locationId = (string) apply_filters(
            'itmar_shopify_inventory_location_id',
            $defaultLocationId,
            $postId,
            $locations
        );
        if ($locationId === '') {
            throw new \RuntimeException('Shopify inventory location is not configured.');
        }

        $this->shopifyRequest(
            ShopifyApi::adminUrl($shopDomain, 'inventory_levels/set.json'),
            [
                'method'  => 'POST',
                'headers' => [
                    'X-Shopify-Access-Token' => $adminToken,
                    'Content-Type'           => 'application/json',
                ],
                'body'    => wp_json_encode([
                    'location_id'       => $locationId,
                    'inventory_item_id' => $inventoryItemId,
                    'available'         => max($quantity, 0),
                ]),
                'timeout' => 20,
            ],
            'Shopify inventory update'
        );
    }

    private function syncImages(
        int $postId,
        string $shopDomain,
        string $adminToken,
        string $productId
    ): void {
        $imageList = $this->shopifyRequest(
            ShopifyApi::adminUrl($shopDomain, "products/{$productId}/images.json"),
            [
                'method'  => 'GET',
                'headers' => ['X-Shopify-Access-Token' => $adminToken],
                'timeout' => 20,
            ],
            'Shopify image retrieval'
        );
        $oldImages = is_array($imageList['images'] ?? null) ? $imageList['images'] : [];

        $attachmentIds = [];
        $thumbnailId = get_post_thumbnail_id($postId);
        if ($thumbnailId) {
            $attachmentIds[] = (int) $thumbnailId;
        }
        $gallery = function_exists('get_field') ? get_field('gallery', $postId) : null;
        if (is_array($gallery)) {
            foreach ($gallery as $image) {
                if (is_array($image) && isset($image['id'])) {
                    $attachmentIds[] = (int) $image['id'];
                }
            }
        }
        $attachmentIds = array_values(array_unique(array_filter($attachmentIds)));

        // 新画像をすべて登録できるまで既存画像を残す。
        $newImageIds = [];
        try {
            foreach ($attachmentIds as $attachmentId) {
                $filePath = get_attached_file($attachmentId);
                if (!$filePath || !is_readable($filePath)) {
                    throw new \RuntimeException(sprintf('WordPress attachment %d is not readable.', $attachmentId));
                }

                $uploadBody = $this->shopifyRequest(
                    ShopifyApi::adminUrl($shopDomain, "products/{$productId}/images.json"),
                    [
                        'method'  => 'POST',
                        'headers' => [
                            'X-Shopify-Access-Token' => $adminToken,
                            'Content-Type'           => 'application/json',
                        ],
                        'body'    => wp_json_encode([
                            'image' => [
                                'attachment' => base64_encode((string) file_get_contents($filePath)),
                                'alt'        => get_the_title($postId),
                            ],
                        ]),
                        'timeout' => 30,
                    ],
                    'Shopify image upload'
                );
                $newImageId = (string) ($uploadBody['image']['id'] ?? '');
                if ($newImageId === '') {
                    throw new \RuntimeException('Shopify image upload returned no image ID.');
                }
                $newImageIds[] = $newImageId;
            }
        } catch (\Throwable $e) {
            // 部分的に追加した新画像だけを戻し、既存画像は維持する。
            foreach ($newImageIds as $newImageId) {
                try {
                    $this->shopifyRequest(
                        ShopifyApi::adminUrl($shopDomain, "products/{$productId}/images/{$newImageId}.json"),
                        [
                            'method'  => 'DELETE',
                            'headers' => ['X-Shopify-Access-Token' => $adminToken],
                            'timeout' => 20,
                        ],
                        'Shopify image rollback'
                    );
                } catch (\Throwable $rollbackError) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[Shopify image rollback] ' . $rollbackError->getMessage());
                    }
                }
            }
            throw $e;
        }

        foreach ($oldImages as $oldImage) {
            $oldImageId = (string) ($oldImage['id'] ?? '');
            if ($oldImageId === '') continue;
            $this->shopifyRequest(
                ShopifyApi::adminUrl($shopDomain, "products/{$productId}/images/{$oldImageId}.json"),
                [
                    'method'  => 'DELETE',
                    'headers' => ['X-Shopify-Access-Token' => $adminToken],
                    'timeout' => 20,
                ],
                'Shopify old image deletion'
            );
        }
    }

    // =========================
    // ★ GraphQL（Admin）公開ヘルパ
    // =========================

    /** ★ Publication ID を名前から解決（Online Store は固定ID -1 を使用） */
    private function resolvePublicationId(string $publicationName = 'Online Store'): string
    {
        if ('Online Store' === $publicationName) {
            return 'gid://shopify/Publication/-1';
        }

        $q = 'query($first: Int!) {
            publications(first: $first) {
                nodes { id name }
            }
        }';

        $data = $this->gql($q, ['first' => 50]);

        foreach ($data['publications']['nodes'] ?? [] as $n) {
            if (($n['name'] ?? '') === $publicationName) {
                return (string) $n['id'];
            }
        }

        throw new \RuntimeException(
            sprintf(
                /* translators: %s: publication name */
                esc_html__('Publication "%s" not found.', 'itmaroon-ec-relate-blocks'),
                esc_html($publicationName)
            )
        );
    }


    /** ★ 商品を ACTIVE に（公開前の安全策） */
    private function ensureProductActive(int $productId): void
    {
        $gid = 'gid://shopify/Product/' . (int) $productId;

        $m = 'mutation SetActive($id: ID!) {
            productUpdate(input: { id: $id, status: ACTIVE }) {
                product { id status }
                userErrors { field message }
            }
        }';

        $res = $this->gql($m, ['id' => $gid]);

        if (! empty($res['productUpdate']['userErrors'])) {
            $errors_json = wp_json_encode($res['productUpdate']['userErrors'], JSON_UNESCAPED_UNICODE);

            throw new \RuntimeException(
                sprintf(
                    /* translators: %s: userErrors JSON */
                    esc_html__('productUpdate failed: %s', 'itmaroon-ec-relate-blocks'),
                    esc_html((string) $errors_json)
                )
            );
        }
    }


    /** ★ 指定販売チャネルに公開（publishablePublish） */
    private function publishToChannel(int $productId, string $publicationName = 'Online Store', bool $activate = true): void
    {
        if ($activate) {
            $this->ensureProductActive($productId);
        }

        $publicationId = $this->resolvePublicationId($publicationName);
        $gid           = 'gid://shopify/Product/' . (int) $productId;

        $m = 'mutation($pid: ID!, $pub: ID!) {
            publishablePublish(id: $pid, input: { publicationId: $pub }) {
                userErrors { field message }
            }
        }';

        $res = $this->gql(
            $m,
            [
                'pid' => $gid,
                'pub' => (string) $publicationId,
            ]
        );

        if (! empty($res['publishablePublish']['userErrors'])) {
            $errors_json = wp_json_encode($res['publishablePublish']['userErrors'], JSON_UNESCAPED_UNICODE);

            throw new \RuntimeException(
                sprintf(
                    /* translators: %s: userErrors JSON */
                    esc_html__('publishablePublish failed: %s', 'itmaroon-ec-relate-blocks'),
                    esc_html((string) $errors_json)
                )
            );
        }
    }


}
