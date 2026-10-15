<?php

declare(strict_types=1);

namespace NobleSchema\Integrations;

class Plugin_Integrations {
    public static function is_acf_active(): bool {
        return function_exists('get_field')
            || self::is_plugin_file_active('advanced-custom-fields/acf.php')
            || self::is_plugin_file_active('advanced-custom-fields-pro/acf.php');
    }

    public static function is_yoast_active(): bool {
        return class_exists('WPSEO_Meta')
            || defined('WPSEO_VERSION')
            || self::is_plugin_file_active('wordpress-seo/wp-seo.php');
    }

    public static function is_events_calendar_active(): bool {
        return class_exists('Tribe__Events__Main')
            || defined('TRIBE_EVENTS_FILE')
            || function_exists('tribe_get_start_date')
            || self::is_plugin_file_active('the-events-calendar/the-events-calendar.php');
    }

    public static function is_wpml_active(): bool {
        if (array_key_exists('noble_schema_test_wpml_active', $GLOBALS)) {
            return !empty($GLOBALS['noble_schema_test_wpml_active']);
        }

        return defined('ICL_SITEPRESS_VERSION')
            || defined('WPML_PLUGIN_FILE')
            || class_exists('SitePress')
            || self::is_plugin_file_active('sitepress-multilingual-cms/sitepress.php');
    }

    /**
     * @return array<string, string> language code => label
     */
    public static function wpml_active_languages(): array {
        if (array_key_exists('noble_schema_test_wpml_languages', $GLOBALS)
            && is_array($GLOBALS['noble_schema_test_wpml_languages'])
        ) {
            $out = [];
            foreach ($GLOBALS['noble_schema_test_wpml_languages'] as $code => $label) {
                $code = strtolower(trim((string) $code));
                $code = preg_replace('/[^a-z0-9_-]/', '', $code) ?? '';
                if ($code === '') {
                    continue;
                }
                $out[$code] = (string) $label !== '' ? (string) $label : $code;
            }

            return $out;
        }

        if (!self::is_wpml_active()) {
            return [];
        }

        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        if (!is_array($languages) || $languages === []) {
            return [];
        }

        $out = [];
        foreach ($languages as $code => $data) {
            $code = is_array($data)
                ? (string) ($data['code'] ?? $data['language_code'] ?? $code)
                : (string) $code;
            $code = strtolower(trim($code));
            $code = preg_replace('/[^a-z0-9_-]/', '', $code) ?? '';
            if ($code === '') {
                continue;
            }
            $label = is_array($data)
                ? (string) ($data['translated_name'] ?? $data['native_name'] ?? $data['display_name'] ?? $code)
                : $code;
            $out[$code] = $label !== '' ? $label : $code;
        }

        return $out;
    }

    /**
     * Language WPML treats as the source of truth for untranslated content.
     */
    public static function wpml_default_language(): string {
        if (array_key_exists('noble_schema_test_wpml_default_language', $GLOBALS)) {
            return self::sanitize_language_code((string) $GLOBALS['noble_schema_test_wpml_default_language']);
        }

        if (!self::is_wpml_active()) {
            return '';
        }

        return self::sanitize_language_code((string) apply_filters('wpml_default_language', null));
    }

    /**
     * Language the current admin request is being served in.
     */
    public static function wpml_current_language(): string {
        if (array_key_exists('noble_schema_test_wpml_current_language', $GLOBALS)) {
            return self::sanitize_language_code((string) $GLOBALS['noble_schema_test_wpml_current_language']);
        }

        if (!self::is_wpml_active()) {
            return '';
        }

        return self::sanitize_language_code((string) apply_filters('wpml_current_language', null));
    }

    public static function post_language_code(int $post_id): string {
        if ($post_id <= 0) {
            return '';
        }

        if (array_key_exists('noble_schema_test_wpml_post_language', $GLOBALS)
            && is_array($GLOBALS['noble_schema_test_wpml_post_language'])
        ) {
            return strtolower(trim((string) ($GLOBALS['noble_schema_test_wpml_post_language'][$post_id] ?? '')));
        }

        if (!self::is_wpml_active()) {
            return '';
        }

        $post_type = get_post_type($post_id);
        if (!is_string($post_type) || $post_type === '') {
            return '';
        }

        $code = apply_filters('wpml_element_language_code', null, [
            'element_id'   => $post_id,
            'element_type' => 'post_' . $post_type,
        ]);

        if (!is_string($code) || $code === '') {
            $details = apply_filters('wpml_post_language_details', null, $post_id);
            if (is_array($details)) {
                $code = (string) ($details['language_code'] ?? '');
            }
        }

        return self::sanitize_language_code((string) $code);
    }

    /**
     * Sibling posts in the same WPML translation group (excludes $post_id).
     *
     * @return list<array{id:int,language_code:string}>
     */
    public static function post_translations(int $post_id): array {
        $post_id = max(0, $post_id);
        if ($post_id <= 0) {
            return [];
        }

        if (array_key_exists('noble_schema_test_wpml_post_translations', $GLOBALS)
            && is_array($GLOBALS['noble_schema_test_wpml_post_translations'])
        ) {
            $map = $GLOBALS['noble_schema_test_wpml_post_translations'][$post_id] ?? [];
            if (!is_array($map)) {
                return [];
            }
            $out = [];
            foreach ($map as $id => $code) {
                // Support either [id => code] or list of ids (code looked up separately).
                if (is_int($id) || ctype_digit((string) $id)) {
                    $sibling_id = (int) $id;
                    $lang = self::sanitize_language_code((string) $code);
                } else {
                    $sibling_id = (int) $code;
                    $lang = self::post_language_code($sibling_id);
                }
                if ($sibling_id <= 0 || $sibling_id === $post_id) {
                    continue;
                }
                $out[] = [
                    'id'            => $sibling_id,
                    'language_code' => $lang,
                ];
            }

            return $out;
        }

        if (!self::is_wpml_active()) {
            return [];
        }

        $post_type = get_post_type($post_id);
        if (!is_string($post_type) || $post_type === '') {
            return [];
        }

        $element_type = 'post_' . $post_type;
        $trid = apply_filters('wpml_element_trid', null, $post_id, $element_type);
        if (!$trid) {
            return [];
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
        if (!is_array($translations) || $translations === []) {
            return [];
        }

        $out = [];
        foreach ($translations as $key => $row) {
            $id = is_object($row)
                ? (int) ($row->element_id ?? 0)
                : (int) ($row['element_id'] ?? 0);
            if ($id <= 0 || $id === $post_id) {
                continue;
            }
            $code = is_object($row)
                ? (string) ($row->language_code ?? $key)
                : (string) ($row['language_code'] ?? $key);
            $out[] = [
                'id'            => $id,
                'language_code' => self::sanitize_language_code($code),
            ];
        }

        return $out;
    }

    /**
     * Term IDs in the same WPML translation group as $term_id (includes $term_id).
     *
     * @return list<int>
     */
    public static function term_translation_ids(int $term_id, string $taxonomy): array {
        $term_id  = max(0, $term_id);
        $taxonomy = sanitize_key($taxonomy);
        if ($term_id <= 0 || $taxonomy === '') {
            return [];
        }

        if (array_key_exists('noble_schema_test_wpml_term_translations', $GLOBALS)
            && is_array($GLOBALS['noble_schema_test_wpml_term_translations'])
        ) {
            $map = $GLOBALS['noble_schema_test_wpml_term_translations'];
            if (isset($map[$term_id]) && is_array($map[$term_id])) {
                $ids = array_map('intval', $map[$term_id]);
                $ids[] = $term_id;

                return array_values(array_unique(array_filter($ids)));
            }

            return [$term_id];
        }

        if (!self::is_wpml_active()) {
            return [$term_id];
        }

        $element_type = 'tax_' . $taxonomy;
        $trid = apply_filters('wpml_element_trid', null, $term_id, $element_type);
        if (!$trid) {
            return [$term_id];
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
        if (!is_array($translations) || $translations === []) {
            return [$term_id];
        }

        $ids = [$term_id];
        foreach ($translations as $row) {
            $id = is_object($row)
                ? (int) ($row->element_id ?? 0)
                : (int) ($row['element_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function sanitize_language_code(string $code): string {
        $code = strtolower(trim($code));

        return preg_replace('/[^a-z0-9_-]/', '', $code) ?? '';
    }

    /**
     * @return array<string, string>
     */
    public static function yoast_fields(): array {
        return [
            'title'                 => __('SEO Title', 'noble-schema'),
            'metadesc'              => __('Meta Description', 'noble-schema'),
            'canonical'             => __('Canonical URL', 'noble-schema'),
            'focuskw'               => __('Focus Keyphrase', 'noble-schema'),
            'opengraph-title'       => __('Open Graph Title', 'noble-schema'),
            'opengraph-description' => __('Open Graph Description', 'noble-schema'),
            'opengraph-image'       => __('Open Graph Image URL', 'noble-schema'),
            'twitter-title'         => __('Twitter Title', 'noble-schema'),
            'twitter-description'   => __('Twitter Description', 'noble-schema'),
            'twitter-image'         => __('Twitter Image URL', 'noble-schema'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function events_calendar_fields(): array {
        return [
            'start_date'       => __('Event Start Date', 'noble-schema'),
            'end_date'         => __('Event End Date', 'noble-schema'),
            'all_day'          => __('All Day Event', 'noble-schema'),
            'cost'             => __('Event Cost', 'noble-schema'),
            'website'          => __('Event Website URL', 'noble-schema'),
            'venue_name'       => __('Venue Name', 'noble-schema'),
            'venue_address'    => __('Venue Street Address', 'noble-schema'),
            'venue_full_address' => __('Venue Full Address', 'noble-schema'),
            'venue_city'       => __('Venue City', 'noble-schema'),
            'venue_region'     => __('Venue State/Province', 'noble-schema'),
            'venue_zip'        => __('Venue Postal Code', 'noble-schema'),
            'venue_country'    => __('Venue Country', 'noble-schema'),
            'venue_phone'      => __('Venue Phone', 'noble-schema'),
            'venue_website'    => __('Venue Website URL', 'noble-schema'),
            'venue_place'      => __('Venue (Place object)', 'noble-schema'),
            'organizer_name'   => __('Organizer Name', 'noble-schema'),
            'organizer_email'  => __('Organizer Email', 'noble-schema'),
            'organizer_phone'  => __('Organizer Phone', 'noble-schema'),
            'organizer_website'=> __('Organizer Website URL', 'noble-schema'),
        ];
    }

    private static function is_plugin_file_active(string $plugin_file): bool {
        if (!function_exists('is_plugin_active')) {
            if (!defined('ABSPATH')) {
                return false;
            }
            $plugin_php = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($plugin_php)) {
                require_once $plugin_php;
            }
        }

        return function_exists('is_plugin_active') && is_plugin_active($plugin_file);
    }
}
