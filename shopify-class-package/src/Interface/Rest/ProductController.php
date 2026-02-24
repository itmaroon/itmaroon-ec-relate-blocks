<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

if (! defined('ABSPATH')) exit;

final class ProductController extends BaseController
{

    /**
     * REST のルート登録（商品情報の取得）
     */
    public function registerRest(): void
    {
        // 公開APIにするなら publicAccess()、RESTノンス必須にするなら gate(null,'wp_rest',false)
        register_rest_route($this->ns(), '/get-product', [[
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'getProductInfo'],
            'permission_callback' => '__return_true',
            'args' => [
                'fields'  => ['required' => true, 'type' => 'array'],
                'itemNum' => ['required' => false, 'type' => 'integer'],
            ],
        ]]);

        register_rest_route($this->ns(), '/get-collections', [[
            'methods'             => WP_REST_Server::READABLE, // GET
            'callback'            => [$this, 'getUsedProductCategories'],
            'permission_callback' => '__return_true',
        ]]);
    }

    /**
     * WP の各種フック登録（保存/削除/cron）
     */
    public function registerWpHooks(): void
    {
        // 投稿削除前：Shopify 側の削除
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 1);

        // 投稿保存：Shopify 商品の作成/更新・削除
        add_action('save_post', [$this, 'onSavePost'], 20, 2);

        // 単発同期ジョブ
        add_action('itmar_shopify_sync_cron', [$this, 'syncProductFromPost'], 10, 1);
        // ステータス遷移監視（ドラフト→公開でメタを消す）
        add_action('transition_post_status', [$this, 'onTransitionPostStatus'], 10, 3);
        // ゴミ箱からの復元
        add_action('untrash_post', [$this, 'onUntrashPost'], 10, 1);
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
            $adminQueryStr = $this->build_admin_products_query($categoryIds, $searchText);

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

    private function build_admin_products_query(array $categoryIds, string $searchText): string
    {
        $norm = [];
        foreach ($categoryIds as $cid) {
            $cid = $this->normalize_taxonomy_category_id($cid);
            if ($cid === '') continue;
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $cid)) continue;
            $norm[] = $cid;
        }

        $qParts = [];

        // Storefrontで取れる（公開中）に寄せる：published_status を入れておくのが無難
        // ※ channel 指定など複雑にする場合は quoted value が必要になるケースがあります。:contentReference[oaicite:4]{index=4}
        $qParts[] = 'published_status:published';

        if (!empty($norm)) {
            $or = [];
            foreach ($norm as $cid) {
                $or[] = 'category_id:"' . $this->escape_search_value($cid) . '"';
            }
            $qParts[] = '(' . implode(' OR ', $or) . ')';
        }

        // ★検索文字列：フィールド名なし term（default）で複数フィールド検索 :contentReference[oaicite:6]{index=6}
        if ($searchText !== '') {
            // スペースを含むなら "..." にしてフレーズ扱いにするのが無難
            $qParts[] = '"' . $this->escape_search_value($searchText) . '"';
            // ※スペース区切りを AND 検索にしたいなら、クォートせずにそのまま入れる案もあります。
            // 検索構文は whitespace で term を連結でき、Connective（AND/OR）も使えます。:contentReference[oaicite:7]{index=7}
        }

        return implode(' AND ', $qParts);
    }

    private function admin_fetch_ids_page_and_count($shopDomain, $adminToken, $first, $after, $queryStr, $includeCount)
    {
        $shopDomain = sanitize_text_field((string) $shopDomain);
        $adminToken = sanitize_text_field((string) $adminToken);
        $endpoint = esc_url_raw('https://' . $shopDomain . '/admin/api/2025-04/graphql.json');

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

    private function storefront_fetch_products_by_ids($shopDomain, $storefrontTk, array $ids, $graphqlFieldStr)
    {
        $shopDomain   = sanitize_text_field((string) $shopDomain);
        $storefrontTk = sanitize_text_field((string) $storefrontTk);

        if (empty($ids)) return [];

        $endpoint = esc_url_raw('https://' . $shopDomain . '/api/2025-04/graphql.json');

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

        $endpoint = esc_url_raw('https://' . $shop_domain . '/admin/api/2025-04/graphql.json');

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

    public function onBeforeDeletePost(int $postId): void
    {
        // ここは “product” 固定ではなく、オプションで定義された投稿タイプを尊重
        $productPostType = (string) get_option('product_post') ?: 'product';
        if (get_post_type($postId) !== $productPostType) return;

        $shopifyId = get_post_meta($postId, 'shopify_product_id', true);
        if (!$shopifyId) return;

        $shopDomain = (string) get_option('shopify_shop_domain');
        $adminToken = (string) get_option('shopify_admin_token');
        if ($shopDomain === '' || $adminToken === '') return;

        wp_remote_request("https://{$shopDomain}/admin/api/2025-04/products/{$shopifyId}.json", [
            'method'  => 'DELETE',
            'headers' => ['X-Shopify-Access-Token' => $adminToken],
            'timeout' => 20,
        ]);

        // ローカルの関連メタも削除
        delete_post_meta($postId, 'shopify_product_id');
        delete_post_meta($postId, 'shopify_variant_id');
    }

    public function onSavePost(int $postId, \WP_Post $post): void
    {
        $productPostType = (string) get_option('product_post') ?: 'product';
        if ($post->post_type !== $productPostType) return;

        // 自動保存・リビジョンは無視
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ('revision' === get_post_type($postId)) return;

        $shopDomain = (string) get_option('shopify_shop_domain');
        $adminToken = (string) get_option('shopify_admin_token');
        if ($adminToken === '' || $shopDomain === '') return;

        // ❶ ゴミ箱へ → Shopify は削除せず、ステータスを draft/archived にする
        if ($post->post_status === 'trash') {
            $shopifyId = get_post_meta($postId, 'shopify_product_id', true);
            if ($shopifyId) {
                $this->updateShopifyProductStatus($shopifyId, 'draft'); // or 'archived'
            }
            return; // ← 削除もメタ消去もしない
        }

        // ❷ 下書き → Shopify も draft に
        if ($post->post_status === 'draft') {
            $shopifyId = get_post_meta($postId, 'shopify_product_id', true);
            if ($shopifyId) {
                $this->updateShopifyProductStatus($shopifyId, 'draft');
            }
            return;
        }

        // 公開以外は何もしない
        if ($post->post_status !== 'publish') return;

        // 二重スケジュール防止
        if (wp_next_scheduled('itmar_shopify_sync_cron', [$postId])) return;

        // 少し遅延させて（保存完了後）同期実行
        wp_schedule_single_event(time() + 5, 'itmar_shopify_sync_cron', [$postId]);
    }

    //下書きからの遷移でメタデータを制御
    public function onTransitionPostStatus(string $new, string $old, \WP_Post $post): void
    {
        // 対象の投稿タイプのみ
        $productPostType = (string) get_option('product_post') ?: 'product';
        if ($post->post_type !== $productPostType) {
            return;
        }

        // ドラフト → 公開 のときだけ実行
        if ($old === 'draft' && $new === 'publish') {
            delete_post_meta($post->ID, 'shopify_product_id');
            delete_post_meta($post->ID, 'shopify_variant_id');
            // （任意）ログ
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[Shopify] Cleared linkage meta on publish (post_id=%d)', $post->ID));
            }
        }
    }

    public function onUntrashPost(int $postId): void
    {
        $productPostType = (string) get_option('product_post') ?: 'product';
        if (get_post_type($postId) !== $productPostType) return;

        $shopifyId = get_post_meta($postId, 'shopify_product_id', true);
        if ($shopifyId) {
            // 復元時点の WP ステータスに合わせて Shopify を戻す
            $status = get_post_status($postId);
            if ($status === 'publish') {
                $this->updateShopifyProductStatus($shopifyId, 'active');
                // ★ 復元時点でも公開を保証（Online Store 公開）
                try {
                    $channelName = (string) get_option('shopify_channel_name');
                    $this->publishToChannel((int)$shopifyId, $channelName); // ★ 追加
                } catch (\Throwable $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) error_log('[Shopify publish on untrash] ' . $e->getMessage());
                }
                // 最新内容を反映したいなら同期もキック
                if (!wp_next_scheduled('itmar_shopify_sync_cron', [$postId])) {
                    wp_schedule_single_event(time() + 5, 'itmar_shopify_sync_cron', [$postId]);
                }
            } elseif ($status === 'draft') {
                $this->updateShopifyProductStatus($shopifyId, 'draft');
            }
        }
    }


    /**
     * WP → Shopify 同期の本体（cron から呼ばれる）
     */
    public function syncProductFromPost(int $postId): void
    {
        $productPostType = (string) get_option('product_post') ?: 'product';
        if (get_post_type($postId) !== $productPostType) return;

        // 投稿情報
        $title       = get_the_title($postId);
        $description = get_the_excerpt($postId);
        $imageUrl    = get_the_post_thumbnail_url($postId, 'full');
        $price       = get_post_meta($postId, 'prices_sales_price', true) ?: '0';
        $regular_price = get_post_meta($postId, 'prices_list_price', true) ?: '0';
        $quantity    = get_post_meta($postId, 'quantity', true) ?: '0';

        // Shopify 接続情報
        $shopDomain = (string) get_option('shopify_shop_domain');
        $adminToken = (string) get_option('shopify_admin_token');
        $channelName = (string) get_option('shopify_channel_name');
        if ($adminToken === '' || $shopDomain === '') return;

        // バリアント
        $variant = [
            'price'                => (string) $price,
            'compare_at_price'     => (string) $regular_price,
            'option1'              => 'Default Title',
            'inventory_management' => 'shopify',
            'inventory_policy'     => 'deny',
        ];

        // 商品データ
        $productData = [
            'product' => [
                'title'     => $title,
                'body_html' => $description,
                'variants'  => [$variant],
            ],
        ];

        // 既存 or 新規
        $existingId = get_post_meta($postId, 'shopify_product_id', true);
        if ($existingId) {
            $resp = wp_remote_request("https://{$shopDomain}/admin/api/2025-04/products/{$existingId}.json", [
                'method'  => 'PUT',
                'headers' => [
                    'X-Shopify-Access-Token' => $adminToken,
                    'Content-Type'           => 'application/json',
                ],
                'body'    => wp_json_encode($productData),
                'timeout' => 20,
            ]);
        } else {
            $resp = wp_remote_post("https://{$shopDomain}/admin/api/2025-04/products.json", [
                'headers' => [
                    'X-Shopify-Access-Token' => $adminToken,
                    'Content-Type'           => 'application/json',
                ],
                'body'    => wp_json_encode($productData),
                'timeout' => 20,
            ]);
            $body = json_decode(wp_remote_retrieve_body($resp), true);
            if (!empty($body['product']['id'])) {
                update_post_meta($postId, 'shopify_product_id', $body['product']['id']);
                update_post_meta($postId, 'shopify_variant_id', $body['product']['variants'][0]['id'] ?? '');
                $existingId = $body['product']['id'];
            }
        }

        // 作成/更新後に Online Store へ公開（販売チャネル割当）
        if ($existingId) {
            try {
                $this->publishToChannel((int)$existingId, $channelName); // 別チャネルにしたい場合は名前を渡す
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) error_log('[Shopify publish] ' . $e->getMessage());
            }
        }

        // 在庫同期
        $variantId = get_post_meta($postId, 'shopify_variant_id', true);
        if ($variantId) {
            // variant → inventory_item_id
            $vResp = wp_remote_get("https://{$shopDomain}/admin/api/2025-04/variants/{$variantId}.json", [
                'headers' => ['X-Shopify-Access-Token' => $adminToken],
                'timeout' => 20,
            ]);
            $vBody = json_decode(wp_remote_retrieve_body($vResp), true);
            $inventoryItemId = $vBody['variant']['inventory_item_id'] ?? null;

            if ($inventoryItemId) {
                // location 一覧
                $locResp = wp_remote_get("https://{$shopDomain}/admin/api/2025-04/locations.json", [
                    'headers' => ['X-Shopify-Access-Token' => $adminToken],
                    'timeout' => 20,
                ]);
                $locBody  = json_decode(wp_remote_retrieve_body($locResp), true);
                $locations = $locBody['locations'] ?? [];

                if (!empty($locations)) {
                    $stockQty = max((int)$quantity, 0);

                    // まず全ロケーションを 0 に
                    foreach ($locations as $loc) {
                        $locId = $loc['id'];
                        wp_remote_post("https://{$shopDomain}/admin/api/2025-04/inventory_levels/set.json", [
                            'headers' => [
                                'X-Shopify-Access-Token' => $adminToken,
                                'Content-Type'           => 'application/json',
                            ],
                            'body'    => wp_json_encode([
                                'location_id'       => $locId,
                                'inventory_item_id' => $inventoryItemId,
                                'available'         => 0,
                            ]),
                            'timeout' => 20,
                        ]);
                    }

                    // 先頭ロケーションに在庫を設定
                    $mainLocId = $locations[0]['id'];
                    wp_remote_post("https://{$shopDomain}/admin/api/2025-04/inventory_levels/set.json", [
                        'headers' => [
                            'X-Shopify-Access-Token' => $adminToken,
                            'Content-Type'           => 'application/json',
                        ],
                        'body'    => wp_json_encode([
                            'location_id'       => $mainLocId,
                            'inventory_item_id' => $inventoryItemId,
                            'available'         => $stockQty,
                        ]),
                        'timeout' => 20,
                    ]);
                }
            }
        }

        // 画像同期（既存IDがある場合）
        if ($existingId) {
            // 既存画像を削除
            $imgListResp = wp_remote_get("https://{$shopDomain}/admin/api/2025-04/products/{$existingId}/images.json", [
                'headers' => ['X-Shopify-Access-Token' => $adminToken],
                'timeout' => 20,
            ]);
            $imgList = json_decode(wp_remote_retrieve_body($imgListResp), true);
            foreach (($imgList['images'] ?? []) as $img) {
                $imageId = $img['id'];
                wp_remote_request("https://{$shopDomain}/admin/api/2025-04/products/{$existingId}/images/{$imageId}.json", [
                    'method'  => 'DELETE',
                    'headers' => ['X-Shopify-Access-Token' => $adminToken],
                    'timeout' => 20,
                ]);
            }

            // ギャラリー と アイキャッチ
            $images = [];
            $gallery = function_exists('get_field') ? get_field('gallery', $postId) : null; // ACF 前提なら存在確認
            $thumbId = get_post_thumbnail_id($postId);
            if ($thumbId) {
                $images[] = (int)$thumbId;
            }
            if ($gallery && is_array($gallery)) {
                foreach ($gallery as $img) {
                    if (isset($img['id'])) $images[] = (int)$img['id'];
                }
            }

            foreach ($images as $attachmentId) {
                $filePath = get_attached_file($attachmentId);
                if ($filePath && file_exists($filePath)) {
                    $imageData = base64_encode((string) file_get_contents($filePath));
                    $uploadResp = wp_remote_post("https://{$shopDomain}/admin/api/2025-04/products/{$existingId}/images.json", [
                        'headers' => [
                            'X-Shopify-Access-Token' => $adminToken,
                            'Content-Type'           => 'application/json',
                        ],
                        'body'    => wp_json_encode([
                            'image' => [
                                'attachment' => $imageData,
                                'alt'        => get_the_title($postId),
                            ],
                        ]),
                        'timeout' => 30,
                    ]);
                    // ログ（任意）
                    $upBody = json_decode(wp_remote_retrieve_body($uploadResp), true);
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Shopify image upload: ' . print_r($upBody, true));
                    }
                }
            }
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
    private function publishToChannel(int $productId, string $publicationName = 'Online Store'): void
    {
        $this->ensureProductActive($productId);

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


    //Shopify の商品ステータス更新ヘルパ
    private function updateShopifyProductStatus(string $productId, string $status): void
    {
        $shopDomain = (string) get_option('shopify_shop_domain');
        $adminToken = (string) get_option('shopify_admin_token');
        if (!$shopDomain || !$adminToken) return;

        $status = in_array($status, ['active', 'draft', 'archived'], true) ? $status : 'draft';

        wp_remote_request("https://{$shopDomain}/admin/api/2025-04/products/{$productId}.json", [
            'method'  => 'PUT',
            'headers' => [
                'X-Shopify-Access-Token' => $adminToken,
                'Content-Type'           => 'application/json',
            ],
            'body'    => wp_json_encode(['product' => ['id' => $productId, 'status' => $status]]),
            'timeout' => 20,
        ]);
    }
}
