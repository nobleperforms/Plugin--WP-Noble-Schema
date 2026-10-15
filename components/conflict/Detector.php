<?php

namespace NobleSchema\Conflict;

use NobleSchema\Admin\Access;
use NobleSchema\Tools\Log;

class Detector {
    public const OPT_LOGGED_KEYS = 'ns_conflict_warning_keys';

    public static function register(): void {
        add_action('admin_notices', [self::class, 'render_admin_notice']);
    }

    /**
     * @return array<int, array{plugin: string, settings_url: ?string, message: string}>
     */
    public static function detect(): array {
        self::ensure_plugin_api();

        $conflicts = [];

        foreach (self::plugin_definitions() as $definition) {
            if (!self::is_any_plugin_active($definition['files'])) {
                continue;
            }

            if (isset($definition['outputs_schema']) && is_callable($definition['outputs_schema'])) {
                if (!$definition['outputs_schema']()) {
                    continue;
                }
            }

            $settings_url = $definition['settings_path'] !== null
                ? admin_url($definition['settings_path'])
                : null;

            $conflicts[] = [
                'plugin'       => $definition['name'],
                'settings_url' => $settings_url,
                'message'      => sprintf(
                    /* translators: 1: plugin name, 2: where to disable schema output */
                    __(
                        '%1$s is active and may output schema JSON-LD by default. Disable its schema or JSON-LD output—not the entire plugin—in %2$s to avoid duplicate structured data.',
                        'noble-schema'
                    ),
                    $definition['name'],
                    $definition['setting_hint']
                ),
            ];
        }

        return $conflicts;
    }

    public static function render_admin_notice(): void {
        if (!Access::can_manage_schema()) {
            return;
        }

        $conflicts = self::detect();
        self::maybe_log_conflicts($conflicts);

        if ($conflicts === []) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>';
        esc_html_e('Other schema plugins detected', 'noble-schema');
        echo '</strong></p><ul>';

        foreach ($conflicts as $conflict) {
            echo '<li>' . esc_html($conflict['message']);

            if ($conflict['settings_url'] !== null) {
                echo ' <a href="' . esc_url($conflict['settings_url']) . '">';
                esc_html_e('Open settings', 'noble-schema');
                echo '</a>';
            }

            echo '</li>';
        }

        echo '</ul></div>';
    }

    /**
     * Write one system-log warning per active conflict; re-log if it clears then returns.
     *
     * @param array<int, array{plugin: string, settings_url: ?string, message: string}> $conflicts
     */
    public static function maybe_log_conflicts(array $conflicts): void {
        $logged = get_option(self::OPT_LOGGED_KEYS, []);
        if (!is_array($logged)) {
            $logged = [];
        }

        $active = [];
        $changed = false;

        foreach ($conflicts as $conflict) {
            $key = self::conflict_key($conflict);
            $active[$key] = true;
            if (isset($logged[$key])) {
                continue;
            }

            Log::warning($conflict['message'], [
                'category' => 'conflict_detected',
                'plugin'   => $conflict['plugin'],
            ]);
            $logged[$key] = time();
            $changed = true;
        }

        $pruned = array_intersect_key($logged, $active);
        if ($pruned !== $logged) {
            $logged = $pruned;
            $changed = true;
        }

        if ($changed) {
            update_option(self::OPT_LOGGED_KEYS, $logged, false);
        }
    }

    /**
     * @param array{plugin: string, settings_url: ?string, message: string} $conflict
     */
    private static function conflict_key(array $conflict): string {
        return 'plugin:' . sanitize_key((string) ($conflict['plugin'] ?? ''));
    }

    /**
     * @return array<int, array{name: string, files: array<int, string>, settings_path: ?string, setting_hint: string, outputs_schema?: callable(): bool}>
     */
    private static function plugin_definitions(): array {
        return [
            [
                'name'           => 'Yoast SEO',
                'files'          => ['wordpress-seo/wp-seo.php'],
                'settings_path'  => 'admin.php?page=wpseo_page_settings#/site-features',
                'setting_hint'   => __('Yoast SEO → Settings → Site features (Schema)', 'noble-schema'),
                'outputs_schema' => [self::class, 'yoast_outputs_schema'],
            ],
            [
                'name'           => 'Rank Math',
                'files'          => ['seo-by-rank-math/rank-math.php'],
                'settings_path'  => 'admin.php?page=rank-math-options-titles',
                'setting_hint'   => __('Rank Math → Titles & Meta → Schema', 'noble-schema'),
            ],
            [
                'name'           => 'All in One SEO',
                'files'          => [
                    'all-in-one-seo-pack/all_in_one_seo_pack.php',
                    'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
                ],
                'settings_path'  => 'admin.php?page=aioseo-search-appearance#/schema-markup',
                'setting_hint'   => __('AIOSEO → Search Appearance → Schema Markup', 'noble-schema'),
            ],
            [
                'name'           => 'Schema Pro',
                'files'          => [
                    'wp-schema-pro/wp-schema-pro.php',
                    'schema-pro/schema-pro.php',
                ],
                'settings_path'  => 'admin.php?page=bsf-schema-pro',
                'setting_hint'   => __('Schema Pro → Settings', 'noble-schema'),
            ],
        ];
    }

    /**
     * Yoast Site features → Schema (`enable_schema` in wpseo option).
     */
    public static function yoast_outputs_schema(): bool {
        $options = get_option('wpseo', []);
        if (!is_array($options)) {
            $options = [];
        }

        // Default when missing is enabled (matches Yoast's Options_Helper default).
        $enabled = $options['enable_schema'] ?? true;
        if ($enabled === false || $enabled === 0 || $enabled === '0') {
            return false;
        }

        // Respect programmatic disable via wpseo_json_ld_output (same check Yoast uses).
        $probe = [
            '_deprecated' => 'Please use the "wpseo_schema_*" filters to extend the Yoast SEO schema data - see the WPSEO_Schema class.',
        ];
        $filtered = apply_filters('wpseo_json_ld_output', $probe, '');
        if ($filtered === false || $filtered === []) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, string> $plugin_files
     */
    private static function is_any_plugin_active(array $plugin_files): bool {
        foreach ($plugin_files as $plugin_file) {
            if (is_plugin_active($plugin_file)) {
                return true;
            }
        }

        return false;
    }

    private static function ensure_plugin_api(): void {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
}
