<?php

declare(strict_types=1);

namespace NobleSchema\Targeting;

class Specialty_Context {
    /**
     * @return array<string, array{label: string, kind: string}>
     */
    public static function definitions(): array {
        $defs = [
            'front_page' => [
                'label' => __('Front Page', 'noble-schema'),
                'kind'  => 'page',
            ],
            'posts_page' => [
                'label' => __('Posts Page', 'noble-schema'),
                'kind'  => 'page',
            ],
            'privacy_policy' => [
                'label' => __('Privacy Policy', 'noble-schema'),
                'kind'  => 'page',
            ],
            '404' => [
                'label' => __('404 Page', 'noble-schema'),
                'kind'  => 'virtual',
            ],
            'search' => [
                'label' => __('Search Results', 'noble-schema'),
                'kind'  => 'virtual',
            ],
        ];

        if (self::woocommerce_active()) {
            $defs['wc_shop'] = [
                'label' => __('WooCommerce Shop', 'noble-schema'),
                'kind'  => 'page',
            ];
            $defs['wc_cart'] = [
                'label' => __('WooCommerce Cart', 'noble-schema'),
                'kind'  => 'page',
            ];
            $defs['wc_checkout'] = [
                'label' => __('WooCommerce Checkout', 'noble-schema'),
                'kind'  => 'page',
            ];
            $defs['wc_myaccount'] = [
                'label' => __('WooCommerce My Account', 'noble-schema'),
                'kind'  => 'page',
            ];
            $defs['wc_terms'] = [
                'label' => __('WooCommerce Terms', 'noble-schema'),
                'kind'  => 'page',
            ];
        }

        /**
         * Filter the Specialty Pages registry.
         *
         * @param array<string, array{label: string, kind: string}> $defs
         */
        $filtered = apply_filters('noble_schema_specialty_pages', $defs);

        return is_array($filtered) ? $filtered : $defs;
    }

    /**
     * @return list<array{value: string, label: string, kind: string}>
     */
    public static function available(): array {
        $options = [];
        foreach (self::definitions() as $key => $def) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            $label = (string) ($def['label'] ?? $key);
            $kind = (string) ($def['kind'] ?? 'page');
            if ($kind === 'page' && self::resolve_post_id($key) <= 0) {
                $label = sprintf(
                    /* translators: %s: specialty page label */
                    __('%s (not set)', 'noble-schema'),
                    $label
                );
            }
            $options[] = [
                'value' => $key,
                'label' => $label,
                'kind'  => $kind === 'virtual' ? 'virtual' : 'page',
            ];
        }

        return $options;
    }

    /**
     * Specialty keys currently offered in Specialty pages.
     *
     * @return list<string>
     */
    public static function all_targeting_keys(): array {
        return array_values(array_filter(array_map(
            static fn (array $opt): string => sanitize_key((string) ($opt['value'] ?? '')),
            self::available()
        )));
    }

    public static function is_known(string $key): bool {
        $key = sanitize_key($key);

        return $key !== '' && isset(self::definitions()[$key]);
    }

    public static function is_virtual(string $key): bool {
        $defs = self::definitions();
        $key = sanitize_key($key);

        return isset($defs[$key]) && ($defs[$key]['kind'] ?? '') === 'virtual';
    }

    public static function resolve_post_id(string $key): int {
        $key = sanitize_key($key);

        return match ($key) {
            'front_page' => (get_option('show_on_front') === 'page')
                ? (int) get_option('page_on_front')
                : 0,
            'posts_page' => (int) get_option('page_for_posts'),
            'privacy_policy' => (int) get_option('wp_page_for_privacy_policy'),
            'wc_shop' => (int) get_option('woocommerce_shop_page_id'),
            'wc_cart' => (int) get_option('woocommerce_cart_page_id'),
            'wc_checkout' => (int) get_option('woocommerce_checkout_page_id'),
            'wc_myaccount' => (int) get_option('woocommerce_myaccount_page_id'),
            'wc_terms' => (int) get_option('woocommerce_terms_page_id'),
            default => 0,
        };
    }

    /**
     * Current virtual specialty key for the main query, or null.
     */
    public static function current_key(): ?string {
        if (is_admin()) {
            return null;
        }

        if (is_404()) {
            return '404';
        }

        if (is_search()) {
            return 'search';
        }

        return null;
    }

    /**
     * @return array{
     *   specialty_key: string,
     *   archive_key: string,
     *   post_id: int,
     *   post_type: string,
     *   page_template: string,
     *   block_types: string[],
     *   taxonomies: array<string, string[]>,
     *   specialty_title: string,
     *   specialty_url: string,
     *   is_specialty: bool
     * }
     */
    public static function context_from_key(string $specialty_key): array {
        $specialty_key = sanitize_key($specialty_key);
        $meta = self::meta_for_key($specialty_key);

        return [
            'specialty_key'   => $specialty_key,
            'archive_key'     => '',
            'post_id'         => 0,
            'post_type'       => '',
            'page_template'   => '',
            'block_types'     => [],
            'taxonomies'      => [],
            'specialty_title' => $meta['title'],
            'specialty_url'   => $meta['url'],
            'is_specialty'    => true,
        ];
    }

    /**
     * @return array{title: string, url: string}
     */
    public static function meta_for_key(string $specialty_key): array {
        $specialty_key = sanitize_key($specialty_key);
        $defs = self::definitions();
        $label = (string) ($defs[$specialty_key]['label'] ?? $specialty_key);

        if ($specialty_key === '404') {
            return [
                'title' => $label,
                'url'   => home_url('/'),
            ];
        }

        if ($specialty_key === 'search') {
            $url = function_exists('get_search_link') ? (string) get_search_link('') : home_url('/?s=');
            if ($url === '') {
                $url = home_url('/?s=');
            }

            return [
                'title' => $label,
                'url'   => $url,
            ];
        }

        $post_id = self::resolve_post_id($specialty_key);
        if ($post_id > 0) {
            $title = function_exists('get_the_title') ? (string) get_the_title($post_id) : '';
            $url = (string) get_permalink($post_id);

            return [
                'title' => $title !== '' ? $title : $label,
                'url'   => $url !== '' ? $url : home_url('/'),
            ];
        }

        return [
            'title' => $label,
            'url'   => home_url('/'),
        ];
    }

    private static function woocommerce_active(): bool {
        return class_exists('WooCommerce') || function_exists('WC');
    }
}
