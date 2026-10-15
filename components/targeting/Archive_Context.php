<?php

declare(strict_types=1);

namespace NobleSchema\Targeting;

class Archive_Context {
    /**
     * Coarse archive key for the main query, or null when not on a supported archive.
     */
    public static function current_key(): ?string {
        $resolved = self::current();

        return $resolved['archive_key'] ?? null;
    }

    /**
     * Concrete archive identity for cache/print, or null.
     */
    public static function current_identity(): ?string {
        $resolved = self::current();

        return $resolved['archive_identity'] ?? null;
    }

    /**
     * @return array{archive_key: string, archive_identity: string}|null
     */
    public static function current(): ?array {
        if (is_admin()) {
            return null;
        }

        if (is_home() && !is_front_page()) {
            return [
                'archive_key'      => 'home',
                'archive_identity' => 'home',
            ];
        }

        if (is_post_type_archive()) {
            $post_type = get_query_var('post_type');
            if (is_array($post_type)) {
                $post_type = $post_type[0] ?? '';
            }
            $post_type = sanitize_key((string) $post_type);
            if ($post_type !== '' && self::post_type_has_archive($post_type)) {
                $key = 'post_type:' . $post_type;

                return [
                    'archive_key'      => $key,
                    'archive_identity' => $key,
                ];
            }
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (is_object($term) && !empty($term->taxonomy) && isset($term->slug)) {
                $tax = sanitize_key((string) $term->taxonomy);
                $slug = sanitize_title((string) $term->slug);
                if ($tax !== '' && $slug !== '') {
                    return [
                        'archive_key'      => 'taxonomy:' . $tax,
                        'archive_identity' => 'taxonomy:' . $tax . ':' . $slug,
                    ];
                }
            }
        }

        if (is_author()) {
            $author_id = (int) get_query_var('author');
            if ($author_id <= 0) {
                $obj = get_queried_object();
                $author_id = is_object($obj) ? (int) ($obj->ID ?? 0) : 0;
            }
            if ($author_id > 0) {
                return [
                    'archive_key'      => 'author',
                    'archive_identity' => 'author:' . $author_id,
                ];
            }
        }

        if (is_date()) {
            $year = (int) get_query_var('year');
            $month = (int) get_query_var('monthnum');
            $day = (int) get_query_var('day');
            if ($year > 0 && $month > 0 && $day > 0) {
                $identity = sprintf('date:%04d/%02d/%02d', $year, $month, $day);
            } elseif ($year > 0 && $month > 0) {
                $identity = sprintf('date:%04d/%02d', $year, $month);
            } elseif ($year > 0) {
                $identity = sprintf('date:%04d', $year);
            } else {
                return null;
            }

            return [
                'archive_key'      => 'date',
                'archive_identity' => $identity,
            ];
        }

        return null;
    }

    /**
     * @return array{
     *   archive_key: string,
     *   archive_identity: string,
     *   archive_post_type: string,
     *   archive_title: string,
     *   archive_url: string,
     *   post_id: int,
     *   post_type: string,
     *   page_template: string,
     *   block_types: string[],
     *   taxonomies: array<string, string[]>,
     *   is_archive: bool
     * }
     */
    public static function context_from_identity(string $archive_identity): array {
        $archive_identity = self::normalize_identity($archive_identity);
        $meta = self::meta_for_identity($archive_identity);
        $coarse = self::coarse_key_from_identity($archive_identity);

        return [
            'archive_key'       => $coarse,
            'archive_identity'  => $archive_identity,
            'archive_post_type' => $meta['post_type'],
            'archive_title'     => $meta['title'],
            'archive_url'       => $meta['url'],
            'post_id'           => 0,
            'post_type'         => '',
            'page_template'     => '',
            'block_types'       => [],
            'taxonomies'        => [],
            'is_archive'        => true,
        ];
    }

    /**
     * Backward-compatible: accepts coarse keys or concrete identities.
     *
     * @return array{
     *   archive_key: string,
     *   archive_identity: string,
     *   archive_post_type: string,
     *   archive_title: string,
     *   archive_url: string,
     *   post_id: int,
     *   post_type: string,
     *   page_template: string,
     *   block_types: string[],
     *   taxonomies: array<string, string[]>,
     *   is_archive: bool
     * }
     */
    public static function context_from_key(string $archive_key): array {
        $archive_key = trim($archive_key);
        if ($archive_key === '') {
            return self::context_from_identity('home');
        }

        // Concrete identity if it has more segments than the coarse form.
        if (self::looks_like_identity($archive_key)) {
            return self::context_from_identity($archive_key);
        }

        return self::context_from_identity($archive_key);
    }

    /**
     * @return array{value: string, label: string}[]
     */
    public static function available_archives(): array {
        $options = [];

        $posts_page_id = (int) get_option('page_for_posts');
        if ($posts_page_id > 0) {
            $options[] = [
                'value' => 'home',
                'label' => sprintf(
                    /* translators: %s: posts page title */
                    __('Blog posts page (%s)', 'noble-schema'),
                    get_the_title($posts_page_id) ?: __('Posts', 'noble-schema')
                ),
            ];
        }

        foreach (get_post_types(['public' => true], 'objects') as $slug => $type) {
            if (!self::post_type_has_archive((string) $slug)) {
                continue;
            }
            $label = (string) ($type->labels->name ?? $slug);
            $options[] = [
                'value' => 'post_type:' . $slug,
                'label' => sprintf(
                    /* translators: %s: post type label */
                    __('%s archive', 'noble-schema'),
                    $label
                ),
            ];
        }

        foreach (get_taxonomies(['public' => true], 'objects') as $tax => $object) {
            $tax = sanitize_key((string) $tax);
            if ($tax === '') {
                continue;
            }
            $label = is_object($object)
                ? (string) ($object->labels->name ?? $tax)
                : $tax;
            $options[] = [
                'value' => 'taxonomy:' . $tax,
                'label' => sprintf(
                    /* translators: %s: taxonomy label */
                    __('%s archives', 'noble-schema'),
                    $label
                ),
            ];
        }

        $options[] = [
            'value' => 'author',
            'label' => __('Author archives', 'noble-schema'),
        ];
        $options[] = [
            'value' => 'date',
            'label' => __('Date archives', 'noble-schema'),
        ];

        return $options;
    }

    /**
     * Coarse targeting keys currently offered in Archive pages.
     *
     * @return list<string>
     */
    public static function all_targeting_keys(): array {
        return array_values(array_map(
            static fn (array $opt): string => (string) ($opt['value'] ?? ''),
            array_filter(
                self::available_archives(),
                static fn ($opt): bool => is_array($opt) && ($opt['value'] ?? '') !== ''
            )
        ));
    }

    public static function is_valid_targeting_key(string $key): bool {
        $key = trim($key);
        if ($key === 'home' || $key === 'author' || $key === 'date') {
            return true;
        }
        if (str_starts_with($key, 'post_type:')) {
            $slug = sanitize_key(substr($key, strlen('post_type:')));

            return $slug !== '';
        }
        if (str_starts_with($key, 'taxonomy:')) {
            $rest = substr($key, strlen('taxonomy:'));
            // Coarse only: taxonomy:{tax} — reject identities with term segment.
            if ($rest === '' || str_contains($rest, ':')) {
                return false;
            }

            return sanitize_key($rest) === $rest;
        }

        return false;
    }

    public static function coarse_key_from_identity(string $identity): string {
        $identity = self::normalize_identity($identity);
        if ($identity === 'home' || $identity === 'author' || $identity === 'date') {
            return $identity;
        }
        if (str_starts_with($identity, 'post_type:')) {
            $slug = sanitize_key(substr($identity, strlen('post_type:')));

            return $slug !== '' ? 'post_type:' . $slug : 'home';
        }
        if (str_starts_with($identity, 'taxonomy:')) {
            $rest = substr($identity, strlen('taxonomy:'));
            $parts = explode(':', $rest, 2);
            $tax = sanitize_key($parts[0] ?? '');

            return $tax !== '' ? 'taxonomy:' . $tax : 'home';
        }
        if (str_starts_with($identity, 'author:')) {
            return 'author';
        }
        if (str_starts_with($identity, 'date:')) {
            return 'date';
        }

        return $identity;
    }

    /**
     * Expand a coarse targeting key into concrete cache/print identities.
     *
     * @return list<string>
     */
    public static function expand_to_identities(string $coarse_key): array {
        $coarse_key = trim($coarse_key);
        if (!self::is_valid_targeting_key($coarse_key)) {
            return [];
        }

        if ($coarse_key === 'home' || str_starts_with($coarse_key, 'post_type:')) {
            return [$coarse_key];
        }

        if (str_starts_with($coarse_key, 'taxonomy:')) {
            $tax = sanitize_key(substr($coarse_key, strlen('taxonomy:')));
            if ($tax === '') {
                return [];
            }
            $terms = get_terms([
                'taxonomy'   => $tax,
                'hide_empty' => true,
            ]);
            if (!is_array($terms)) {
                return [];
            }
            $ids = [];
            foreach ($terms as $term) {
                if (!is_object($term) || empty($term->slug)) {
                    continue;
                }
                $slug = sanitize_title((string) $term->slug);
                if ($slug === '') {
                    continue;
                }
                $ids[] = 'taxonomy:' . $tax . ':' . $slug;
            }

            return array_values(array_unique($ids));
        }

        if ($coarse_key === 'author') {
            return self::discover_author_identities();
        }

        if ($coarse_key === 'date') {
            return self::discover_date_identities();
        }

        return [];
    }

    public static function post_type_has_archive(string $post_type): bool {
        $object = get_post_type_object($post_type);
        if (!$object || empty($object->has_archive)) {
            return false;
        }

        return (bool) $object->public;
    }

    /**
     * @return array{post_type: string, title: string, url: string}
     */
    public static function meta_for_key(string $archive_key): array {
        return self::meta_for_identity(
            self::looks_like_identity($archive_key) ? $archive_key : $archive_key
        );
    }

    /**
     * @return array{post_type: string, title: string, url: string}
     */
    public static function meta_for_identity(string $identity): array {
        $identity = self::normalize_identity($identity);

        if ($identity === 'home') {
            $posts_page_id = (int) get_option('page_for_posts');
            $title = $posts_page_id > 0 ? (string) get_the_title($posts_page_id) : __('Blog', 'noble-schema');
            $url = $posts_page_id > 0 ? (string) get_permalink($posts_page_id) : home_url('/');

            return [
                'post_type' => 'post',
                'title'     => $title !== '' ? $title : __('Blog', 'noble-schema'),
                'url'       => $url !== '' ? $url : home_url('/'),
            ];
        }

        if (str_starts_with($identity, 'post_type:')) {
            $slug = sanitize_key(substr($identity, strlen('post_type:')));
            $object = get_post_type_object($slug);
            $title = $object ? (string) ($object->labels->name ?? $slug) : $slug;
            $url = get_post_type_archive_link($slug);
            if (!is_string($url) || $url === '') {
                $url = home_url('/');
            }

            return [
                'post_type' => $slug,
                'title'     => $title,
                'url'       => $url,
            ];
        }

        if (str_starts_with($identity, 'taxonomy:')) {
            $rest = substr($identity, strlen('taxonomy:'));
            $parts = explode(':', $rest, 2);
            $tax = sanitize_key($parts[0] ?? '');
            $term_slug = isset($parts[1]) ? sanitize_title($parts[1]) : '';
            if ($tax !== '' && $term_slug !== '') {
                $term = get_term_by('slug', $term_slug, $tax);
                $title = (is_object($term) && !empty($term->name)) ? (string) $term->name : $term_slug;
                $link = is_object($term) ? get_term_link($term) : home_url('/');
                $url = is_string($link) ? $link : home_url('/');

                return [
                    'post_type' => '',
                    'title'     => $title,
                    'url'       => $url !== '' ? $url : home_url('/'),
                ];
            }
            $object = get_taxonomy($tax);
            $title = $object ? (string) ($object->labels->name ?? $tax) : $tax;

            return [
                'post_type' => '',
                'title'     => $title,
                'url'       => home_url('/'),
            ];
        }

        if (str_starts_with($identity, 'author:')) {
            $author_id = (int) substr($identity, strlen('author:'));
            $name = $author_id > 0 ? (string) get_the_author_meta('display_name', $author_id) : '';
            $url = $author_id > 0 ? (string) get_author_posts_url($author_id) : home_url('/');

            return [
                'post_type' => '',
                'title'     => $name !== '' ? $name : __('Author', 'noble-schema'),
                'url'       => $url !== '' ? $url : home_url('/'),
            ];
        }

        if (str_starts_with($identity, 'date:')) {
            $spec = substr($identity, strlen('date:'));
            $title = $spec;
            $url = home_url('/');
            if (preg_match('#^(\d{4})/(\d{2})/(\d{2})$#', $spec, $m)) {
                $title = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
                if (function_exists('get_day_link')) {
                    $url = (string) get_day_link((int) $m[1], (int) $m[2], (int) $m[3]);
                }
            } elseif (preg_match('#^(\d{4})/(\d{2})$#', $spec, $m)) {
                $title = sprintf('%s-%s', $m[1], $m[2]);
                if (function_exists('get_month_link')) {
                    $url = (string) get_month_link((int) $m[1], (int) $m[2]);
                }
            } elseif (preg_match('#^(\d{4})$#', $spec, $m)) {
                $title = $m[1];
                if (function_exists('get_year_link')) {
                    $url = (string) get_year_link((int) $m[1]);
                }
            }

            return [
                'post_type' => '',
                'title'     => $title,
                'url'       => $url !== '' ? $url : home_url('/'),
            ];
        }

        return [
            'post_type' => '',
            'title'     => '',
            'url'       => home_url('/'),
        ];
    }

    private static function normalize_identity(string $identity): string {
        return trim($identity);
    }

    private static function looks_like_identity(string $key): bool {
        if (str_starts_with($key, 'taxonomy:')) {
            $rest = substr($key, strlen('taxonomy:'));

            return str_contains($rest, ':');
        }
        if (str_starts_with($key, 'author:')) {
            return true;
        }
        if (str_starts_with($key, 'date:')) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function discover_author_identities(): array {
        if (array_key_exists('noble_schema_test_archive_authors', $GLOBALS)) {
            $ids = array_map('intval', (array) $GLOBALS['noble_schema_test_archive_authors']);

            return array_values(array_map(
                static fn (int $id): string => 'author:' . $id,
                array_values(array_filter($ids, static fn (int $id): bool => $id > 0))
            ));
        }

        $users = get_users([
            'has_published_posts' => true,
            'fields'              => ['ID'],
        ]);
        $ids = [];
        foreach ((array) $users as $user) {
            $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
            if ($id > 0) {
                $ids[] = 'author:' . $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private static function discover_date_identities(): array {
        if (array_key_exists('noble_schema_test_archive_dates', $GLOBALS)) {
            return array_values(array_unique(array_filter(array_map('strval', (array) $GLOBALS['noble_schema_test_archive_dates']))));
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->posts)) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT DISTINCT YEAR(post_date) AS y, MONTH(post_date) AS m, DAY(post_date) AS d
             FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')
             ORDER BY y, m, d"
        );
        if (!is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $y = (int) ($row->y ?? 0);
            $m = (int) ($row->m ?? 0);
            $d = (int) ($row->d ?? 0);
            if ($y <= 0) {
                continue;
            }
            $ids[] = sprintf('date:%04d', $y);
            if ($m > 0) {
                $ids[] = sprintf('date:%04d/%02d', $y, $m);
            }
            if ($m > 0 && $d > 0) {
                $ids[] = sprintf('date:%04d/%02d/%02d', $y, $m, $d);
            }
        }

        return array_values(array_unique($ids));
    }
}
