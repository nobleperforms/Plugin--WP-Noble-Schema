<?php

declare(strict_types=1);

namespace NobleSchema\Removal;

use NobleSchema\Admin\Onboarding;
use NobleSchema\Admin\Settings_Page;
use NobleSchema\Cache\Archive_Cache;
use NobleSchema\Cache\Block_Usage_Cache;
use NobleSchema\Generator\Regen_Queue;
use NobleSchema\SchemaOrg\Template_Factory;
use NobleSchema\Update\Updater;

/**
 * Permanently purge selected Noble Schema project data (tables stay installed).
 */
class Data_Remover {
    public const SCOPE_EVERYTHING = 'everything';
    public const SCOPE_SETTINGS = 'settings';
    public const SCOPE_TEMPLATES = 'templates';
    public const SCOPE_CONFIGS = 'configs';
    public const SCOPE_SNAPSHOTS = 'snapshots';
    public const SCOPE_LOGS = 'logs';

    public const CONFIRM_PHRASE = 'DELETE';

    private string $plugin_dir;

    public function __construct(?string $plugin_dir = null) {
        $this->plugin_dir = $plugin_dir ?? dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array {
        return [
            self::SCOPE_EVERYTHING,
            self::SCOPE_SETTINGS,
            self::SCOPE_TEMPLATES,
            self::SCOPE_CONFIGS,
            self::SCOPE_SNAPSHOTS,
            self::SCOPE_LOGS,
        ];
    }

    /**
     * @return array{scope:string, counts:array<string, int>, message:string, templates_skipped:bool}
     */
    public function purge(string $scope): array {
        $scope = sanitize_key($scope);
        if (!in_array($scope, self::scopes(), true)) {
            throw new \InvalidArgumentException(__('Unknown data removal scope.', 'noble-schema'));
        }

        $counts = [
            'settings'  => 0,
            'templates' => 0,
            'configs'   => 0,
            'targets'   => 0,
            'fields'    => 0,
            'manual'    => 0,
            'snapshots' => 0,
            'options'   => 0,
            'logs'      => 0,
        ];
        $templates_skipped = false;

        if ($scope === self::SCOPE_EVERYTHING || $scope === self::SCOPE_SETTINGS) {
            $counts['settings'] = $this->purge_settings();
        }

        if ($scope === self::SCOPE_TEMPLATES) {
            $counts['templates'] = $this->purge_custom_templates();
        } elseif ($scope === self::SCOPE_EVERYTHING) {
            if ($this->custom_templates_writable()) {
                $counts['templates'] = $this->purge_custom_templates();
            } else {
                $templates_skipped = true;
            }
        }

        if ($scope === self::SCOPE_EVERYTHING || $scope === self::SCOPE_CONFIGS) {
            $config_counts = $this->purge_configs();
            $counts['configs'] = $config_counts['configs'];
            $counts['targets'] = $config_counts['targets'];
            $counts['fields'] = $config_counts['fields'];
            $counts['manual'] = $config_counts['manual'];
        }

        if (
            $scope === self::SCOPE_EVERYTHING
            || $scope === self::SCOPE_CONFIGS
            || $scope === self::SCOPE_SNAPSHOTS
        ) {
            $snap = $this->purge_snapshots_and_caches();
            $counts['snapshots'] = $snap['snapshots'];
            $counts['options'] = $snap['options'];
        }

        if ($scope === self::SCOPE_EVERYTHING || $scope === self::SCOPE_LOGS) {
            $counts['logs'] = $this->purge_logs();
        }

        return [
            'scope'             => $scope,
            'counts'            => $counts,
            'templates_skipped' => $templates_skipped,
            'message'           => $this->summary_message($scope, $counts, $templates_skipped),
        ];
    }

    public function custom_templates_writable(): bool {
        return (new Template_Factory($this->plugin_dir . '/templates'))->custom_writable();
    }

    private function purge_settings(): int {
        $keys = [
            Settings_Page::OPT_ENABLE,
            Settings_Page::OPT_USERNAME,
            Settings_Page::OPT_REPO,
            Settings_Page::OPT_TOKEN,
            Updater::OPT_LAST_CHECK,
            Updater::OPT_PLUGIN_VERSION,
            Onboarding::OPT_STATE,
            Onboarding::OPT_SETTINGS_TOUCHED,
            Settings_Page::OPT_DELETE_ON_UNINSTALL,
            Settings_Page::OPT_RESTRICT,
            Settings_Page::OPT_ADMIN_USER_IDS,
            Settings_Page::OPT_LOGGING_ENABLED,
            Settings_Page::OPT_LOGGING_RETENTION,
            Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES,
            \NobleSchema\Coverage\Coverage_Scan_Queue::OPT_JOB,
            \NobleSchema\Coverage\Coverage_Scan_Queue::OPT_RESULTS,
        ];

        foreach ($keys as $key) {
            delete_option($key);
        }

        \NobleSchema\Tools\Log::unschedule_cleanup();

        return count($keys);
    }

    private function purge_logs(): int {
        return \NobleSchema\Tools\Log::purge_all();
    }

    private function purge_custom_templates(): int {
        $factory = new Template_Factory($this->plugin_dir . '/templates');
        if (!$factory->custom_writable()) {
            throw new \RuntimeException(
                __('Custom templates can’t be deleted because the uploads templates directory isn’t writable on this environment.', 'noble-schema')
            );
        }

        return $factory->delete_all_custom();
    }

    /**
     * @return array{configs:int, targets:int, fields:int, manual:int}
     */
    private function purge_configs(): array {
        global $wpdb;

        $tables = [
            'configs' => $wpdb->prefix . 'ns_configs',
            'targets' => $wpdb->prefix . 'ns_config_targets',
            'fields'  => $wpdb->prefix . 'ns_config_fields',
            'manual'  => $wpdb->prefix . 'ns_manual_values',
        ];

        $counts = [
            'configs' => 0,
            'targets' => 0,
            'fields'  => 0,
            'manual'  => 0,
        ];

        foreach ($tables as $key => $table) {
            $counts[$key] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix map.
            $wpdb->query("TRUNCATE TABLE {$table}");
        }

        return $counts;
    }

    /**
     * @return array{snapshots:int, options:int}
     */
    private function purge_snapshots_and_caches(): array {
        global $wpdb;

        $snapshots_table = $wpdb->prefix . 'ns_snapshots';
        $snapshots = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$snapshots_table}");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix.
        $wpdb->query("TRUNCATE TABLE {$snapshots_table}");

        (new Archive_Cache())->clear_all();
        (new \NobleSchema\Cache\Specialty_Cache())->clear_all();
        (new \NobleSchema\Cache\Sitewide_Cache())->clear_all();
        (new Block_Usage_Cache())->invalidate_all();

        $options_cleared = 0;
        foreach ([
            Regen_Queue::OPTION_KEY,
            Regen_Queue::OPTION_TOTAL,
            Regen_Queue::OPTION_JOB,
            Regen_Queue::OPTION_CANCELLED,
        ] as $option) {
            delete_option($option);
            $options_cleared++;
        }

        wp_clear_scheduled_hook(Regen_Queue::CRON_HOOK);

        return [
            'snapshots' => $snapshots,
            'options'   => $options_cleared,
        ];
    }

    /**
     * @param array<string, int> $counts
     */
    private function summary_message(string $scope, array $counts, bool $templates_skipped = false): string {
        $parts = [];

        if ($scope === self::SCOPE_SETTINGS || $scope === self::SCOPE_EVERYTHING) {
            $parts[] = __('plugin settings', 'noble-schema');
        }
        if ($scope === self::SCOPE_TEMPLATES) {
            $parts[] = sprintf(
                /* translators: %d: number of custom templates deleted */
                __('%d custom templates', 'noble-schema'),
                (int) $counts['templates']
            );
        } elseif ($scope === self::SCOPE_EVERYTHING && !$templates_skipped) {
            $parts[] = sprintf(
                /* translators: %d: number of custom templates deleted */
                __('%d custom templates', 'noble-schema'),
                (int) $counts['templates']
            );
        }
        if ($scope === self::SCOPE_CONFIGS || $scope === self::SCOPE_EVERYTHING) {
            $parts[] = sprintf(
                /* translators: %d: number of schema configs deleted */
                __('%d configs', 'noble-schema'),
                (int) $counts['configs']
            );
        }
        if (
            $scope === self::SCOPE_SNAPSHOTS
            || $scope === self::SCOPE_CONFIGS
            || $scope === self::SCOPE_EVERYTHING
        ) {
            $parts[] = sprintf(
                /* translators: %d: number of snapshots deleted */
                __('%d snapshots + caches', 'noble-schema'),
                (int) $counts['snapshots']
            );
        }
        if ($scope === self::SCOPE_LOGS || $scope === self::SCOPE_EVERYTHING) {
            $parts[] = sprintf(
                /* translators: %d: number of log rows deleted */
                __('%d system log entries', 'noble-schema'),
                (int) $counts['logs']
            );
        }

        $message = sprintf(
            /* translators: %s: comma-separated summary of deleted items */
            __('Data removal complete: %s.', 'noble-schema'),
            implode(', ', $parts)
        );

        if ($templates_skipped) {
            $message .= ' ' . __('Custom templates were skipped because the uploads templates directory isn’t writable on this environment.', 'noble-schema');
        }

        return $message;
    }
}
