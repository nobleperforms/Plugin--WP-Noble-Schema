<?php

declare(strict_types=1);

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null) {
        return ((int) $number === 1) ? $single : $plural;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin') {
        return 'https://example.com/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $params = $args[0];
            $url = '';
        } elseif (count($args) >= 2 && is_array($args[0])) {
            $params = $args[0];
            $url = (string) $args[1];
        } else {
            $params = [$args[0] => $args[1] ?? null];
            $url = (string) ($args[2] ?? '');
        }
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query($params);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!class_exists('WP_Post')) {
    #[\AllowDynamicProperties]
    class WP_Post {
        public $ID = 0;
        public $post_title = '';
        public $post_type = 'post';
        public $post_status = 'publish';
        public $post_content = '';

        public function __construct(array $data = []) {
            foreach ($data as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id) {
        $posts = $GLOBALS['noble_schema_test_posts'] ?? [];

        return $posts[(int) $post_id] ?? null;
    }
}

if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names', $operator = 'and') {
        $types = $GLOBALS['noble_schema_test_post_types'] ?? ['post' => 'post', 'page' => 'page'];
        if (!is_array($types)) {
            return [];
        }

        return $types;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post_id) {
        if (is_object($post_id)) {
            $post_id = $post_id->ID ?? 0;
        }

        return 'https://example.com/?p=' . (int) $post_id;
    }
}

if (!function_exists('parse_blocks')) {
    function parse_blocks($content) {
        if (array_key_exists('noble_schema_test_parsed_blocks', $GLOBALS)) {
            return $GLOBALS['noble_schema_test_parsed_blocks'];
        }

        return [];
    }
}

if (!function_exists('get_page_template_slug')) {
    function get_page_template_slug($post_id) {
        return '';
    }
}

if (!function_exists('get_object_taxonomies')) {
    function get_object_taxonomies($post_type) {
        return [];
    }
}

if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($post_id, $taxonomy, $args = []) {
        $store = $GLOBALS['noble_schema_test_post_terms'] ?? [];
        $terms = $store[(int) $post_id][(string) $taxonomy] ?? [];
        if (!is_array($terms)) {
            return [];
        }
        $fields = is_array($args) ? (string) ($args['fields'] ?? 'all') : 'all';
        if ($fields === 'ids') {
            return array_values(array_map('intval', $terms));
        }
        if ($fields === 'slugs') {
            return array_values(array_map('strval', $terms));
        }

        return array_values($terms);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower((string) $title);
        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
        return trim($title, '-');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim((string) $str);
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        $store = $GLOBALS['noble_schema_test_post_meta'] ?? [];
        $values = $store[(int) $post_id][(string) $key] ?? [];
        if (!is_array($values)) {
            $values = [$values];
        }
        $values = array_values($values);
        if ($single) {
            return $values[0] ?? '';
        }

        return $values;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false) {
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string) ?? '';
        $string = strip_tags($string);
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string) ?? '';
        }
        return trim($string);
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($attachment_id) {
        return false;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') {
        $info = $GLOBALS['noble_schema_test_bloginfo'] ?? [];
        if ($show === 'url') {
            return $info['url'] ?? 'https://example.com';
        }
        return $info[$show] ?? '';
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url((string) $url, $component);
    }
}

if (!function_exists('url_to_postid')) {
    function url_to_postid($url) {
        $map = $GLOBALS['noble_schema_test_url_to_postid'] ?? [];
        $url = rtrim((string) $url, '/');
        if (isset($map[$url])) {
            return (int) $map[$url];
        }
        $url .= '/';
        return (int) ($map[$url] ?? 0);
    }
}

if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy) {
        $tax = (string) $taxonomy;
        if ($tax === '') {
            return false;
        }
        $list = $GLOBALS['noble_schema_test_taxonomies'] ?? ['category', 'post_tag'];
        return in_array($tax, $list, true);
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) {
        $base = rtrim((string) (($GLOBALS['noble_schema_test_bloginfo']['url'] ?? 'https://example.com')), '/');
        $path = (string) $path;
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('site_url')) {
    function site_url($path = '', $scheme = null) {
        return home_url($path, $scheme);
    }
}

if (!function_exists('get_locale')) {
    function get_locale() {
        return 'en_US';
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale() {
        return get_locale();
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string() {
        return (string) get_option('timezone_string', 'UTC');
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        $tz = wp_timezone_string();
        try {
            return new \DateTimeZone($tz !== '' ? $tz : 'UTC');
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        $timestamp = $timestamp ?? time();
        $timezone = $timezone instanceof \DateTimeZone ? $timezone : wp_timezone();
        return (new \DateTimeImmutable('@' . (int) $timestamp))
            ->setTimezone($timezone)
            ->format($format);
    }
}

if (!function_exists('get_site_icon_url')) {
    function get_site_icon_url($size = 512, $url = '', $blog_id = 0) {
        return '';
    }
}

if (!function_exists('get_theme_mod')) {
    function get_theme_mod($name, $default = false) {
        return $default;
    }
}

if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail') {
        return false;
    }
}

if (!function_exists('get_privacy_policy_url')) {
    function get_privacy_policy_url() {
        return '';
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['noble_schema_test_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        $GLOBALS['noble_schema_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        unset($GLOBALS['noble_schema_test_options'][$option]);
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return (int) ($GLOBALS['noble_schema_test_current_user_id'] ?? 0);
    }
}

if (!function_exists('user_can')) {
    function user_can($user, $capability) {
        $user_id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
        $caps = $GLOBALS['noble_schema_test_user_caps'][$user_id] ?? [];

        return !empty($caps[$capability]);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args) {
        return user_can(get_current_user_id(), $capability);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return '2026-08-04 12:00:00';
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        $hook = (string) $hook;
        $priority = (int) $priority;
        if (!isset($GLOBALS['wp_filter']) || !is_array($GLOBALS['wp_filter'])) {
            $GLOBALS['wp_filter'] = [];
        }
        $GLOBALS['wp_filter'][$hook][$priority][] = [
            'function'      => $callback,
            'accepted_args' => (int) $accepted_args,
        ];

        return true;
    }
}

if (!function_exists('has_filter')) {
    function has_filter($hook, $function_to_check = false) {
        $hook = (string) $hook;
        if (empty($GLOBALS['wp_filter'][$hook]) || !is_array($GLOBALS['wp_filter'][$hook])) {
            return false;
        }

        if ($function_to_check === false) {
            return true;
        }

        foreach ($GLOBALS['wp_filter'][$hook] as $callbacks) {
            foreach ($callbacks as $entry) {
                if (($entry['function'] ?? null) === $function_to_check) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('remove_all_filters')) {
    function remove_all_filters($hook = null, $priority = false) {
        if ($hook === null) {
            $GLOBALS['wp_filter'] = [];

            return true;
        }

        $hook = (string) $hook;
        if ($priority === false) {
            unset($GLOBALS['wp_filter'][$hook]);

            return true;
        }

        unset($GLOBALS['wp_filter'][$hook][(int) $priority]);
        if (empty($GLOBALS['wp_filter'][$hook])) {
            unset($GLOBALS['wp_filter'][$hook]);
        }

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        $hook = (string) $hook;
        if (empty($GLOBALS['wp_filter'][$hook]) || !is_array($GLOBALS['wp_filter'][$hook])) {
            return $value;
        }

        $priorities = array_keys($GLOBALS['wp_filter'][$hook]);
        sort($priorities, SORT_NUMERIC);

        foreach ($priorities as $priority) {
            foreach ($GLOBALS['wp_filter'][$hook][$priority] as $entry) {
                $fn = $entry['function'] ?? null;
                if (!is_callable($fn)) {
                    continue;
                }
                $accepted = (int) ($entry['accepted_args'] ?? 1);
                $call_args = array_slice(array_merge([$value], $args), 0, max(0, $accepted));
                $value = $fn(...$call_args);
            }
        }

        return $value;
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return !empty($GLOBALS['noble_schema_test_is_admin']);
    }
}

if (!function_exists('is_404')) {
    function is_404() {
        return !empty($GLOBALS['noble_schema_test_is_404']);
    }
}

if (!function_exists('is_search')) {
    function is_search() {
        return !empty($GLOBALS['noble_schema_test_is_search']);
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post = 0) {
        $post_id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        $titles = $GLOBALS['noble_schema_test_post_titles'] ?? [];

        return (string) ($titles[$post_id] ?? ('Post ' . $post_id));
    }
}

if (!function_exists('get_search_link')) {
    function get_search_link($query = '') {
        $q = (string) $query;

        return home_url('/?s=' . rawurlencode($q));
    }
}

if (!function_exists('is_home')) {
    function is_home() {
        return !empty($GLOBALS['noble_schema_test_is_home']);
    }
}

if (!function_exists('is_front_page')) {
    function is_front_page() {
        return !empty($GLOBALS['noble_schema_test_is_front_page']);
    }
}

if (!function_exists('is_post_type_archive')) {
    function is_post_type_archive($post_types = '') {
        return !empty($GLOBALS['noble_schema_test_is_post_type_archive']);
    }
}

if (!function_exists('is_singular')) {
    function is_singular($post_types = '') {
        return !empty($GLOBALS['noble_schema_test_is_singular']);
    }
}

if (!function_exists('is_category')) {
    function is_category($category = '') {
        return !empty($GLOBALS['noble_schema_test_is_category']);
    }
}

if (!function_exists('is_tag')) {
    function is_tag($tag = '') {
        return !empty($GLOBALS['noble_schema_test_is_tag']);
    }
}

if (!function_exists('is_tax')) {
    function is_tax($taxonomy = '', $term = '') {
        return !empty($GLOBALS['noble_schema_test_is_tax']);
    }
}

if (!function_exists('is_author')) {
    function is_author($author = '') {
        return !empty($GLOBALS['noble_schema_test_is_author']);
    }
}

if (!function_exists('is_date')) {
    function is_date() {
        return !empty($GLOBALS['noble_schema_test_is_date']);
    }
}

if (!function_exists('get_query_var')) {
    function get_query_var($var, $default = '') {
        $vars = $GLOBALS['noble_schema_test_query_vars'] ?? [];

        return $vars[$var] ?? $default;
    }
}

if (!function_exists('get_queried_object')) {
    function get_queried_object() {
        return $GLOBALS['noble_schema_test_queried_object'] ?? null;
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object($post_type) {
        $types = $GLOBALS['noble_schema_test_post_types'] ?? [];
        $obj = $types[$post_type] ?? null;
        if (is_object($obj)) {
            return $obj;
        }

        return null;
    }
}

if (!function_exists('get_post_type_archive_link')) {
    function get_post_type_archive_link($post_type) {
        return home_url('/' . sanitize_key((string) $post_type) . '/');
    }
}

if (!function_exists('get_taxonomies')) {
    function get_taxonomies($args = [], $output = 'names', $operator = 'and') {
        $taxes = $GLOBALS['noble_schema_test_taxonomies'] ?? [];
        if (!is_array($taxes)) {
            return [];
        }

        return $taxes;
    }
}

if (!function_exists('get_taxonomy')) {
    function get_taxonomy($taxonomy) {
        $taxes = $GLOBALS['noble_schema_test_taxonomies'] ?? [];

        return $taxes[$taxonomy] ?? false;
    }
}

if (!function_exists('get_terms')) {
    function get_terms($args = []) {
        if (is_string($args)) {
            $taxonomy = $args;
        } else {
            $taxonomy = is_array($args) ? (string) ($args['taxonomy'] ?? '') : '';
        }
        $all = $GLOBALS['noble_schema_test_terms'] ?? [];

        return $all[$taxonomy] ?? [];
    }
}

if (!function_exists('get_term')) {
    function get_term($term_id, $taxonomy = '') {
        $term_id = (int) $term_id;
        foreach ((array) ($GLOBALS['noble_schema_test_terms'] ?? []) as $tax => $terms) {
            if ($taxonomy !== '' && (string) $tax !== (string) $taxonomy) {
                continue;
            }
            foreach ((array) $terms as $term) {
                if (is_object($term) && (int) ($term->term_id ?? 0) === $term_id) {
                    return $term;
                }
            }
        }

        return null;
    }
}

if (!function_exists('get_term_by')) {
    function get_term_by($field, $value, $taxonomy) {
        foreach ((array) (($GLOBALS['noble_schema_test_terms'][$taxonomy] ?? [])) as $term) {
            if (!is_object($term)) {
                continue;
            }
            if ($field === 'slug' && (string) ($term->slug ?? '') === (string) $value) {
                return $term;
            }
            if ($field === 'name' && (string) ($term->name ?? '') === (string) $value) {
                return $term;
            }
            if ($field === 'id' && (int) ($term->term_id ?? 0) === (int) $value) {
                return $term;
            }
        }

        return false;
    }
}

if (!function_exists('get_term_link')) {
    function get_term_link($term, $taxonomy = '') {
        if (is_object($term)) {
            $tax = (string) ($term->taxonomy ?? $taxonomy);
            $slug = (string) ($term->slug ?? '');

            return home_url('/' . $tax . '/' . $slug . '/');
        }

        return home_url('/');
    }
}

if (!function_exists('get_users')) {
    function get_users($args = []) {
        if (array_key_exists('noble_schema_test_users', $GLOBALS)) {
            return $GLOBALS['noble_schema_test_users'];
        }

        return [];
    }
}

if (!function_exists('get_the_author_meta')) {
    function get_the_author_meta($field, $user_id = false) {
        $meta = $GLOBALS['noble_schema_test_author_meta'][$user_id] ?? [];

        return $meta[$field] ?? '';
    }
}

if (!function_exists('get_author_posts_url')) {
    function get_author_posts_url($author_id, $author_nicename = '') {
        return home_url('/author/' . (int) $author_id . '/');
    }
}

if (!function_exists('get_year_link')) {
    function get_year_link($year) {
        return home_url('/' . (int) $year . '/');
    }
}

if (!function_exists('get_month_link')) {
    function get_month_link($year, $month) {
        return home_url(sprintf('/%04d/%02d/', (int) $year, (int) $month));
    }
}

if (!function_exists('get_day_link')) {
    function get_day_link($year, $month, $day) {
        return home_url(sprintf('/%04d/%02d/%02d/', (int) $year, (int) $month, (int) $day));
    }
}

require_once dirname(__DIR__) . '/components/tools/Log.php';
require_once dirname(__DIR__) . '/components/conflict/Detector.php';
require_once dirname(__DIR__) . '/components/support/Paths.php';
require_once dirname(__DIR__) . '/components/templates/Template_Loader.php';
require_once dirname(__DIR__) . '/components/targeting/Matcher.php';
require_once dirname(__DIR__) . '/components/targeting/Post_Meta_Rule.php';
require_once dirname(__DIR__) . '/components/targeting/Language_Rule.php';
require_once dirname(__DIR__) . '/components/targeting/Taxonomy_Rule.php';
require_once dirname(__DIR__) . '/components/targeting/Archive_Context.php';
require_once dirname(__DIR__) . '/components/targeting/Specialty_Context.php';
require_once dirname(__DIR__) . '/components/targeting/Sitewide_Sources.php';
require_once dirname(__DIR__) . '/components/debug/Debug_Url.php';
require_once dirname(__DIR__) . '/components/debug/Debug_Context_Resolver.php';
require_once dirname(__DIR__) . '/components/mapping/Resolver_Interface.php';
require_once dirname(__DIR__) . '/components/integrations/Plugin_Integrations.php';
require_once dirname(__DIR__) . '/components/admin/Language_Guard.php';
require_once dirname(__DIR__) . '/components/cache/Archive_Cache.php';
require_once dirname(__DIR__) . '/components/cache/Specialty_Cache.php';
require_once dirname(__DIR__) . '/components/cache/Sitewide_Cache.php';
require_once dirname(__DIR__) . '/components/schemaorg/Vocabulary.php';
require_once dirname(__DIR__) . '/components/schemaorg/Template_Factory.php';
require_once dirname(__DIR__) . '/components/schema/Schema_Validator.php';
require_once dirname(__DIR__) . '/components/coverage/Coverage_Classifier.php';
require_once dirname(__DIR__) . '/components/coverage/Coverage_Scan_Queue.php';
require_once dirname(__DIR__) . '/components/coverage/Coverage_Aggregator.php';
require_once dirname(__DIR__) . '/components/admin/Settings_Page.php';
require_once dirname(__DIR__) . '/components/admin/Tools_Page.php';
require_once dirname(__DIR__) . '/components/update/Updater.php';
require_once dirname(__DIR__) . '/components/admin/Access.php';
require_once dirname(__DIR__) . '/components/admin/Onboarding.php';
require_once dirname(__DIR__) . '/components/removal/Data_Remover.php';
require_once dirname(__DIR__) . '/components/offboard/Offboard.php';
require_once dirname(__DIR__) . '/components/transfer/Pack.php';

if (!function_exists('trailingslashit')) {
    function trailingslashit($value) {
        return rtrim((string) $value, '/\\') . '/';
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value) {
        return rtrim((string) $value, '/\\');
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) {
        $base = $GLOBALS['noble_schema_test_uploads'] ?? (sys_get_temp_dir() . '/ns-test-uploads');
        if (!empty($GLOBALS['noble_schema_test_uploads_error'])) {
            return [
                'path'    => '',
                'url'     => '',
                'subdir'  => '',
                'basedir' => '',
                'baseurl' => '',
                'error'   => 'Uploads unavailable',
            ];
        }
        if ($create_dir && $base !== '' && !is_dir($base)) {
            mkdir($base, 0777, true);
        }

        return [
            'path'    => $base,
            'url'     => 'http://example.com/uploads',
            'subdir'  => '',
            'basedir' => $base,
            'baseurl' => 'http://example.com/uploads',
            'error'   => false,
        ];
    }
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/ns-test-plugins');
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        return is_dir($target) || mkdir($target, 0777, true);
    }
}

foreach (glob(dirname(__DIR__) . '/components/mapping/*.php') as $path) {
    if (str_ends_with($path, 'Resolver_Interface.php')) {
        continue;
    }

    require_once $path;
}

require_once dirname(__DIR__) . '/components/db/Config_Repository.php';
require_once dirname(__DIR__) . '/components/db/Target_Repository.php';
require_once dirname(__DIR__) . '/components/db/Field_Repository.php';
require_once dirname(__DIR__) . '/components/db/Manual_Value_Repository.php';
require_once dirname(__DIR__) . '/components/db/Snapshot_Repository.php';
require_once dirname(__DIR__) . '/components/generator/Graph_Builder.php';
require_once dirname(__DIR__) . '/components/blocks/Schema_Block.php';
require_once dirname(__DIR__) . '/components/cache/Block_Usage_Cache.php';
require_once dirname(__DIR__) . '/components/generator/Regen_Queue.php';
require_once dirname(__DIR__) . '/components/generator/Generator.php';
