<?php

declare(strict_types=1);

namespace NobleSchema\Admin;

use NobleSchema\Update\Updater;

/**
 * Project-level Schema Settings (plugins-list entry point only).
 */
class Settings_Page {
    public const PAGE_SLUG = 'noble-schema-settings';
    public const OPTION_GROUP = 'ns_schema_settings_group';
    public const GIT_TOKEN_CONSTANT = 'NOBLE_SCHEMA_GIT_TOKEN';

    public const OPT_ENABLE = 'ns_enable_updates';
    public const OPT_USERNAME = 'ns_gh_username';
    public const OPT_REPO = 'ns_gh_repo';
    public const OPT_TOKEN = 'ns_gh_token';
    public const OPT_DELETE_ON_UNINSTALL = 'ns_delete_data_on_uninstall';
    public const OPT_RESTRICT = 'ns_restrict_schema_admin';
    public const OPT_ADMIN_USER_IDS = 'ns_schema_admin_user_ids';
    public const OPT_LOGGING_ENABLED = 'ns_logging_enabled';
    public const OPT_LOGGING_RETENTION = 'ns_logging_retention';
    public const OPT_SITEWIDE_SCHEMA_TYPES = 'ns_sitewide_schema_types';

    /** @var array<string, true> */
    private static array $pending_setting_changes = [];

    private string $plugin_file;

    public function __construct(string $plugin_file) {
        $this->plugin_file = $plugin_file;
    }

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_menu', [$this, 'hide_menu'], 999);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename($this->plugin_file), [$this, 'plugin_action_links']);
        add_action('updated_option', [$this, 'track_option_updated'], 10, 3);
        add_action('added_option', [$this, 'track_option_added'], 10, 2);
        add_action('shutdown', [self::class, 'flush_settings_change_log']);
        $this->maybe_boot_updater();
    }

    public function maybe_boot_updater(): void {
        if (!$this->updates_enabled()) {
            return;
        }

        $username = $this->gh_username();
        $repo = $this->gh_repo();
        if ($username === '' || $repo === '') {
            return;
        }

        new Updater([
            'file_path'       => $this->plugin_file,
            'github_username' => $username,
            'repo_name'       => $repo,
            'access_token'    => $this->gh_token(),
        ]);
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function plugin_action_links(array $links): array {
        if (!Access::can_manage_schema()) {
            return $links;
        }

        $settings = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::page_url()),
            esc_html__('Settings', 'noble-schema')
        );

        return array_merge(['settings' => $settings], $links);
    }

    public function add_menu(): void {
        // Register as a top-level page (then hide it) so the page stays in
        // $_registered_pages. Null-parent submenus leave get_admin_page_title()
        // as null and trigger strip_tags() deprecations on PHP 8.1+.
        $hook = add_menu_page(
            __('Schema Settings', 'noble-schema'),
            __('Schema Settings', 'noble-schema'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
        add_action("load-{$hook}", [$this, 'set_page_title']);
    }

    public function hide_menu(): void {
        remove_menu_page(self::PAGE_SLUG);
    }

    /**
     * Ensure admin-header receives a non-null $title after remove_menu_page().
     */
    public function set_page_title(): void {
        $GLOBALS['title'] = __('Schema Settings', 'noble-schema');
    }

    public function register_settings(): void {
        register_setting(self::OPTION_GROUP, self::OPT_ENABLE, [
            'type'              => 'string',
            'sanitize_callback' => static function ($value): string {
                Onboarding::touch_settings();
                return !empty($value) ? '1' : '';
            },
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_USERNAME, [
            'type'              => 'string',
            'sanitize_callback' => static function ($value): string {
                Onboarding::touch_settings();
                return sanitize_text_field((string) $value);
            },
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_REPO, [
            'type'              => 'string',
            'sanitize_callback' => static fn ($value): string => sanitize_text_field((string) $value),
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_TOKEN, [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_token'],
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_DELETE_ON_UNINSTALL, [
            'type'              => 'string',
            'sanitize_callback' => static fn ($value): string => !empty($value) ? '1' : '',
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_RESTRICT, [
            'type'              => 'string',
            'sanitize_callback' => static fn ($value): string => !empty($value) ? '1' : '',
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_ADMIN_USER_IDS, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_admin_user_ids'],
            'default'           => [],
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_LOGGING_ENABLED, [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_logging_enabled'],
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_LOGGING_RETENTION, [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_logging_retention'],
            'default'           => \NobleSchema\Tools\Log::DEFAULT_RETENTION,
        ]);
        register_setting(self::OPTION_GROUP, self::OPT_SITEWIDE_SCHEMA_TYPES, [
            'type'              => 'string',
            'sanitize_callback' => [self::class, 'sanitize_sitewide_schema_types'],
            'default'           => self::sanitize_sitewide_schema_types(''),
        ]);

        add_action(
            'update_option_' . self::OPT_LOGGING_RETENTION,
            [$this, 'on_logging_retention_updated'],
            10,
            2
        );
    }

    /**
     * Enable/disable logging; purge + unschedule on disable; default retention + schedule on enable.
     */
    public function sanitize_logging_enabled($value): string {
        $was = (string) get_option(self::OPT_LOGGING_ENABLED, '') === '1';
        $now = !empty($value);

        if ($was && !$now) {
            \NobleSchema\Tools\Log::purge_all();
            \NobleSchema\Tools\Log::unschedule_cleanup();
            return '';
        }

        if (!$was && $now) {
            // Require retention; auto-set to 3 months when enabling.
            update_option(self::OPT_LOGGING_RETENTION, \NobleSchema\Tools\Log::DEFAULT_RETENTION);
            \NobleSchema\Tools\Log::schedule_cleanup();
            return '1';
        }

        if ($now) {
            \NobleSchema\Tools\Log::schedule_cleanup();
            return '1';
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    public function sanitize_logging_retention($value): string {
        return \NobleSchema\Tools\Log::sanitize_retention($value);
    }

    /**
     * Apply the new retention window immediately when the option changes.
     *
     * @param mixed $old_value
     * @param mixed $value
     */
    public function on_logging_retention_updated($old_value, $value): void {
        $old = \NobleSchema\Tools\Log::sanitize_retention($old_value);
        $new = \NobleSchema\Tools\Log::sanitize_retention($value);
        if ($old === $new) {
            return;
        }

        // Option is already updated; cleanup uses the new retention length.
        \NobleSchema\Tools\Log::cleanup_expired();
        \NobleSchema\Tools\Log::schedule_cleanup();
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    public function sanitize_admin_user_ids($value): array {
        $restrict = !empty($_POST[self::OPT_RESTRICT]); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies.
        if (!$restrict) {
            // Keep previous allowlist while restriction is off.
            return Access::sanitize_user_ids(get_option(self::OPT_ADMIN_USER_IDS, []), false);
        }

        return Access::sanitize_user_ids($value, true);
    }

    public function sanitize_token($value): string {
        if ($this->using_predefined_token()) {
            return (string) get_option(self::OPT_TOKEN, '');
        }

        $value = sanitize_text_field((string) $value);
        // Keep existing token if the password field is submitted empty.
        if ($value === '') {
            return (string) get_option(self::OPT_TOKEN, '');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function tracked_option_keys(): array {
        return [
            self::OPT_ENABLE,
            self::OPT_USERNAME,
            self::OPT_REPO,
            self::OPT_TOKEN,
            self::OPT_DELETE_ON_UNINSTALL,
            self::OPT_RESTRICT,
            self::OPT_ADMIN_USER_IDS,
            self::OPT_LOGGING_ENABLED,
            self::OPT_LOGGING_RETENTION,
            self::OPT_SITEWIDE_SCHEMA_TYPES,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function setting_labels(): array {
        return [
            self::OPT_ENABLE              => __('Allow automatic update checks', 'noble-schema'),
            self::OPT_USERNAME            => __('GitHub username', 'noble-schema'),
            self::OPT_REPO                => __('Repository name', 'noble-schema'),
            self::OPT_TOKEN               => __('GitHub token', 'noble-schema'),
            self::OPT_DELETE_ON_UNINSTALL => __('Delete all data on uninstall', 'noble-schema'),
            self::OPT_RESTRICT            => __('Restrict schema admin access', 'noble-schema'),
            self::OPT_ADMIN_USER_IDS      => __('Schema admin users', 'noble-schema'),
            self::OPT_LOGGING_ENABLED     => __('Enable system logs', 'noble-schema'),
            self::OPT_LOGGING_RETENTION   => __('Log retention', 'noble-schema'),
            self::OPT_SITEWIDE_SCHEMA_TYPES => __('Sitewide schema types', 'noble-schema'),
        ];
    }

    /**
     * @param mixed $old_value
     * @param mixed $value
     */
    public function track_option_updated(string $option, $old_value, $value): void {
        if (!in_array($option, self::tracked_option_keys(), true)) {
            return;
        }
        if ($old_value == $value) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- intentional for option arrays/scalars
            return;
        }
        self::queue_setting_change($option);
    }

    /**
     * @param mixed $value
     */
    public function track_option_added(string $option, $value): void {
        if (!in_array($option, self::tracked_option_keys(), true)) {
            return;
        }
        self::queue_setting_change($option);
    }

    public static function queue_setting_change(string $option): void {
        if (!in_array($option, self::tracked_option_keys(), true)) {
            return;
        }
        self::$pending_setting_changes[$option] = true;
    }

    /**
     * Write one settings_updated log for any queued option keys, then clear the queue.
     */
    public static function flush_settings_change_log(): void {
        if (self::$pending_setting_changes === []) {
            return;
        }

        $changed = array_keys(self::$pending_setting_changes);
        self::$pending_setting_changes = [];

        $labels = self::setting_labels();
        $names = [];
        foreach ($changed as $key) {
            $names[] = $labels[$key] ?? $key;
        }

        $message = sprintf(
            /* translators: %s: comma-separated setting labels */
            __('Settings updated: %s', 'noble-schema'),
            implode(', ', $names)
        );

        // Allow logging while disabling (sanitize still sees logging enabled).
        if (!\NobleSchema\Tools\Log::enabled() && !in_array(self::OPT_LOGGING_ENABLED, $changed, true)) {
            return;
        }

        \NobleSchema\Tools\Log::info($message, [
            'category' => 'settings_updated',
            'changed'  => $changed,
        ]);
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $base = plugin_dir_url($this->plugin_file);
        $path = plugin_dir_path($this->plugin_file);
        $css = $path . 'dist/styles/admin.css';
        $js = $path . 'dist/scripts/admin.js';

        wp_enqueue_style(
            'noble-schema-admin',
            $base . 'dist/styles/admin.css',
            ['dashicons'],
            is_readable($css) ? (string) filemtime($css) : '0.1.0'
        );
        wp_enqueue_script(
            'noble-schema-admin',
            $base . 'dist/scripts/admin.js',
            [],
            is_readable($js) ? (string) filemtime($js) : '0.1.0',
            true
        );
        wp_localize_script('noble-schema-admin', 'nsNobleSchemaAdmin', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'settingsNonce'   => wp_create_nonce('ns_schema_settings'),
            'currentUserId'   => (string) (int) get_current_user_id(),
            'pluginUpdateUrl' => wp_nonce_url(
                self_admin_url(
                    'update.php?action=upgrade-plugin&plugin=' . rawurlencode(plugin_basename($this->plugin_file))
                ),
                'upgrade-plugin_' . plugin_basename($this->plugin_file)
            ),
            'i18n'            => [
                'cancel'              => __('Cancel', 'noble-schema'),
                'ok'                  => __('OK', 'noble-schema'),
                'loggingDisableTitle' => __('Turn off system logs?', 'noble-schema'),
                'loggingDisableBody'  => __('All stored system logs will be deleted permanently. This cannot be undone.', 'noble-schema'),
                'loggingDisableConfirm' => __('Turn off & delete logs', 'noble-schema'),
                'loggingRetentionTitle' => __('Lower log retention?', 'noble-schema'),
                'loggingRetentionBody'  => __('Existing logs outside the shorter retention period may be removed when you save.', 'noble-schema'),
                'loggingRetentionConfirm' => __('Save & apply retention', 'noble-schema'),
                'updatesChecking'     => __('Checking GitHub for updates…', 'noble-schema'),
                'updatesCheckFailed'  => __('Could not check for updates.', 'noble-schema'),
                'updatesCheckLabel'   => __('Check for updates', 'noble-schema'),
                'updatesUpdateNow'    => __('Update Now', 'noble-schema'),
                /* translators: %s: new plugin version */
                'updatesViewDetails'  => __('View version %s details', 'noble-schema'),
            ],
            'onboarding'      => Onboarding::localize_payload(),
        ]);
    }

    public function render(): void {
        if (!Access::can_manage_schema()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'noble-schema'));
        }

        $enable_updates = $this->updates_enabled();
        $gh_username = $this->gh_username();
        $gh_repo = $this->gh_repo();
        $using_predefined = $this->using_predefined_token();
        $gh_token = $using_predefined ? '' : (string) get_option(self::OPT_TOKEN, '');
        $delete_on_uninstall = $this->delete_data_on_uninstall();
        $restrict_access = Access::restriction_enabled();
        $admin_user_options = Access::manage_options_user_options();
        $admin_user_ids = Access::allowed_user_ids();
        if ($restrict_access) {
            $current = (int) get_current_user_id();
            if ($current > 0 && !in_array($current, $admin_user_ids, true)) {
                $admin_user_ids[] = $current;
            }
        }
        $locked_admin_user_ids = $restrict_access
            ? [(string) (int) get_current_user_id()]
            : [];
        $logging_enabled = \NobleSchema\Tools\Log::enabled();
        $logging_retention = \NobleSchema\Tools\Log::retention_slug();
        $logging_retention_choices = \NobleSchema\Tools\Log::retention_choices();
        $sitewide_schema_types = self::sitewide_schema_types();
        $sitewide_type_options = $this->sitewide_type_options();
        $sitewide_locked_types = self::default_sitewide_schema_types();
        $update_last_check = Updater::last_check();

        include dirname(__DIR__) . '/views/settings.php';
    }

    public static function page_url(): string {
        return add_query_arg(
            ['page' => self::PAGE_SLUG],
            admin_url('admin.php')
        );
    }

    /**
     * @return array<string, string>
     */
    public static function exportable_settings(): array {
        return [
            self::OPT_ENABLE              => ((string) get_option(self::OPT_ENABLE, '') === '1') ? '1' : '',
            self::OPT_USERNAME            => (string) get_option(self::OPT_USERNAME, ''),
            self::OPT_REPO                => (string) get_option(self::OPT_REPO, ''),
            self::OPT_TOKEN               => (string) get_option(self::OPT_TOKEN, ''),
            self::OPT_DELETE_ON_UNINSTALL => ((string) get_option(self::OPT_DELETE_ON_UNINSTALL, '') === '1') ? '1' : '',
            // Export restriction toggle only — never user IDs.
            self::OPT_RESTRICT            => Access::restriction_enabled() ? '1' : '',
            self::OPT_LOGGING_ENABLED     => \NobleSchema\Tools\Log::enabled() ? '1' : '',
            self::OPT_LOGGING_RETENTION   => \NobleSchema\Tools\Log::retention_slug(),
            self::OPT_SITEWIDE_SCHEMA_TYPES => self::sitewide_schema_types_string(),
        ];
    }

    /**
     * Display-safe settings summary for import preview (token is masked).
     *
     * @param array<string, mixed> $settings
     * @return array{count:int, items:list<array{key:string,label:string,value:string}>, note:string}
     */
    public static function preview_settings(array $settings): array {
        $labels = self::setting_labels();
        // preview_settings omits admin user IDs (never exported).
        unset($labels[self::OPT_ADMIN_USER_IDS]);

        $items = [];
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $raw = (string) $settings[$key];
            if (
                $key === self::OPT_ENABLE
                || $key === self::OPT_DELETE_ON_UNINSTALL
                || $key === self::OPT_RESTRICT
                || $key === self::OPT_LOGGING_ENABLED
            ) {
                $value = $raw === '1'
                    ? __('Enabled', 'noble-schema')
                    : __('Disabled', 'noble-schema');
            } elseif ($key === self::OPT_LOGGING_RETENTION) {
                $choices = \NobleSchema\Tools\Log::retention_choices();
                $value = $choices[\NobleSchema\Tools\Log::sanitize_retention($raw)] ?? $raw;
            } elseif ($key === self::OPT_TOKEN) {
                $value = self::mask_secret($raw);
            } elseif ($raw === '') {
                $value = __('(empty)', 'noble-schema');
            } else {
                $value = $raw;
            }

            $items[] = [
                'key'   => $key,
                'label' => $label,
                'value' => $value,
            ];
        }

        return [
            'count' => count($items),
            'items' => $items,
            'note'  => $items === []
                ? __('No project settings in this pack yet.', 'noble-schema')
                : '',
        ];
    }

    public static function mask_secret(string $value): string {
        if ($value === '') {
            return __('(empty)', 'noble-schema');
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', max(8, $length - 4)) . substr($value, -4);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function import_settings(array $settings): int {
        $count = 0;
        $map = [
            self::OPT_ENABLE              => static fn ($v): string => !empty($v) ? '1' : '',
            self::OPT_USERNAME            => static fn ($v): string => sanitize_text_field((string) $v),
            self::OPT_REPO                => static fn ($v): string => sanitize_text_field((string) $v),
            self::OPT_TOKEN               => static fn ($v): string => sanitize_text_field((string) $v),
            self::OPT_DELETE_ON_UNINSTALL => static fn ($v): string => !empty($v) ? '1' : '',
            self::OPT_RESTRICT            => static fn ($v): string => !empty($v) ? '1' : '',
            self::OPT_LOGGING_ENABLED     => static fn ($v): string => !empty($v) ? '1' : '',
            self::OPT_LOGGING_RETENTION   => static fn ($v): string => \NobleSchema\Tools\Log::sanitize_retention($v),
            self::OPT_SITEWIDE_SCHEMA_TYPES => [self::class, 'sanitize_sitewide_schema_types'],
        ];

        foreach ($map as $key => $sanitize) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = $sanitize($settings[$key]);
            // Keep the existing token when the pack omits a usable value.
            if ($key === self::OPT_TOKEN && $value === '') {
                continue;
            }
            // Importing logging off does not purge destination logs (Data Removal does).
            if ($key === self::OPT_LOGGING_ENABLED) {
                $was = (string) get_option(self::OPT_LOGGING_ENABLED, '') === '1';
                update_option($key, $value);
                if ($value === '1') {
                    if (!array_key_exists(self::OPT_LOGGING_RETENTION, $settings)) {
                        update_option(self::OPT_LOGGING_RETENTION, \NobleSchema\Tools\Log::DEFAULT_RETENTION);
                    }
                    \NobleSchema\Tools\Log::schedule_cleanup();
                } elseif ($was) {
                    \NobleSchema\Tools\Log::unschedule_cleanup();
                }
                $count++;
                continue;
            }
            update_option($key, $value);
            $count++;
        }

        // Never import foreign user IDs. If restriction is on after import,
        // seed allowlist with the importer so access is never orphaned.
        if (array_key_exists(self::OPT_RESTRICT, $settings)) {
            $restrict = !empty($settings[self::OPT_RESTRICT]);
            if ($restrict) {
                $ids = Access::sanitize_user_ids([], true);
                $importer = (int) get_current_user_id();
                if ($importer > 0 && !in_array($importer, $ids, true)) {
                    $ids[] = $importer;
                    sort($ids);
                }
                if ($ids === []) {
                    // Refuse to enable restriction with nobody allowed.
                    update_option(self::OPT_RESTRICT, '');
                } else {
                    update_option(self::OPT_ADMIN_USER_IDS, $ids);
                    $count++;
                }
            }
            // When toggle is off, leave the destination allowlist alone.
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public static function default_sitewide_schema_types(): array {
        return ['Organization', 'WebSite', 'WebPage'];
    }

    /**
     * Defaults are always retained; the picker locks them as non-removable.
     *
     * @param mixed $value
     */
    public static function sanitize_sitewide_schema_types($value): string {
        $raw = is_array($value) ? $value : [(string) $value];
        $parts = [];
        foreach ($raw as $part) {
            if (is_array($part)) {
                continue;
            }
            foreach (preg_split('/[\s,]+/', (string) $part) ?: [] as $piece) {
                $parts[] = $piece;
            }
        }

        $types = self::default_sitewide_schema_types();
        foreach ($parts as $part) {
            $type = sanitize_text_field(trim((string) $part));
            if ($type === '' || in_array($type, $types, true)) {
                continue;
            }
            $types[] = $type;
        }

        return implode(',', $types);
    }

    /**
     * Picker options: locked defaults first, then installed template types.
     *
     * @param array<string, array<string, mixed>> $templates
     * @param list<string> $selected
     * @return list<array{value:string,label:string}>
     */
    public static function build_sitewide_type_options(array $templates, array $selected = []): array {
        $options = [];
        $add = static function (string $value, string $label) use (&$options): void {
            $value = trim($value);
            if ($value === '' || isset($options[$value])) {
                return;
            }
            $options[$value] = [
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        };

        foreach (self::default_sitewide_schema_types() as $type) {
            $add($type, $type);
        }

        $from_templates = [];
        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }
            $type = trim((string) ($template['schema_type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $label = trim((string) ($template['label'] ?? ''));
            $from_templates[$type] = ($label !== '' && $label !== $type)
                ? sprintf('%s (%s)', $type, $label)
                : $type;
        }
        ksort($from_templates, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($from_templates as $type => $label) {
            $add((string) $type, (string) $label);
        }

        foreach ($selected as $type) {
            $add((string) $type, (string) $type);
        }

        return array_values($options);
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public function sitewide_type_options(): array {
        $loader = new \NobleSchema\Templates\Template_Loader(dirname($this->plugin_file) . '/templates');

        return self::build_sitewide_type_options($loader->all(), self::sitewide_schema_types());
    }

    /**
     * @return list<string>
     */
    public static function sitewide_schema_types(): array {
        $raw = (string) get_option(self::OPT_SITEWIDE_SCHEMA_TYPES, '');
        if (trim($raw) === '') {
            return self::default_sitewide_schema_types();
        }
        $sanitized = self::sanitize_sitewide_schema_types($raw);

        return $sanitized === ''
            ? self::default_sitewide_schema_types()
            : explode(',', $sanitized);
    }

    public static function sitewide_schema_types_string(): string {
        return implode(',', self::sitewide_schema_types());
    }

    public function updates_enabled(): bool {
        return (string) get_option(self::OPT_ENABLE, '') === '1';
    }

    public function delete_data_on_uninstall(): bool {
        return (string) get_option(self::OPT_DELETE_ON_UNINSTALL, '') === '1';
    }

    public function gh_username(): string {
        return (string) get_option(self::OPT_USERNAME, '');
    }

    public function gh_repo(): string {
        return (string) get_option(self::OPT_REPO, '');
    }

    public function using_predefined_token(): bool {
        return defined(self::GIT_TOKEN_CONSTANT) && (string) constant(self::GIT_TOKEN_CONSTANT) !== '';
    }

    public function gh_token(): string {
        if ($this->using_predefined_token()) {
            return (string) constant(self::GIT_TOKEN_CONSTANT);
        }

        return (string) get_option(self::OPT_TOKEN, '');
    }
}
