<?php

declare(strict_types=1);

namespace NobleSchema\Admin;

use NobleSchema\SchemaOrg\Template_Factory;

/**
 * First-run setup guide state and Schema-admin UI hooks.
 */
class Onboarding {
    public const OPT_STATE = 'ns_onboarding_state';
    public const OPT_SETTINGS_TOUCHED = 'ns_settings_touched';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_MINIMIZED = 'minimized';
    public const STATUS_CELEBRATING = 'celebrating';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_COMPLETED = 'completed';

    public const PATH_IMPORT = 'import';
    public const PATH_CHECKLIST = 'checklist';

    /** @var list<string> */
    public const STEP_KEYS = ['settings', 'templates', 'configs', 'viewer'];

    private string $plugin_file;

    public function __construct(string $plugin_file = '') {
        $this->plugin_file = $plugin_file !== '' ? $plugin_file : (defined('ABSPATH') ? '' : '');
        if ($this->plugin_file === '' && function_exists('NobleSchema\\plugin')) {
            try {
                $this->plugin_file = \NobleSchema\plugin()->plugin_file();
            } catch (\Throwable $e) {
                $this->plugin_file = '';
            }
        }
    }

    public function register(): void {
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_notices', [$this, 'render_banner']);
        add_action('admin_footer', [$this, 'render_panel']);
        add_action('admin_enqueue_scripts', [$this, 'maybe_prepare_state'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_banner_assets']);
    }

    /**
     * Merge auto-completion early on Schema admin screens.
     *
     * Reaching a Schema page means the user found the setup area, so the
     * activation banner has done its job and stops following them around.
     */
    public function maybe_prepare_state(string $hook): void {
        if (!$this->is_schema_admin_hook($hook) || !Access::can_manage_schema()) {
            return;
        }

        $before = self::get_state();
        if ($before === null) {
            return;
        }

        // Celebration is one-shot: next Schema page load finalizes and hides the panel.
        $was_celebrating = ($before['status'] ?? '') === self::STATUS_CELEBRATING;

        $tool = isset($_GET['tool']) ? sanitize_key(wp_unslash((string) $_GET['tool'])) : '';
        self::merge_auto_completion($tool !== '' ? $tool : null);
        self::dismiss_banner();

        if ($was_celebrating) {
            self::finalize_celebration();
        }
    }

    /**
     * Banner appears outside Schema screens, so load shared admin CSS there too.
     */
    public function enqueue_banner_assets(string $hook): void {
        if (!Access::can_manage_schema() || !self::show_banner() || $this->is_schema_admin_hook($hook)) {
            return;
        }

        if ($this->plugin_file === '') {
            return;
        }

        $base = plugin_dir_url($this->plugin_file);
        $path = plugin_dir_path($this->plugin_file);
        $css = $path . 'dist/styles/admin.css';
        if (!is_readable($css)) {
            return;
        }

        wp_enqueue_style(
            'noble-schema-admin',
            $base . 'dist/styles/admin.css',
            ['dashicons'],
            (string) filemtime($css)
        );
    }

    /**
     * Link-driven actions run before output so redirects can still send headers.
     *
     * These links work without the admin bundle, which only loads on Schema pages.
     */
    public function handle_actions(): void {
        $action = isset($_GET['ns_onboarding'])
            ? sanitize_key(wp_unslash((string) $_GET['ns_onboarding']))
            : '';
        if (!in_array($action, ['start', 'dismiss_banner', 'reopen'], true)) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
        if (!Access::can_manage_schema() || !wp_verify_nonce($nonce, 'ns_onboarding_banner')) {
            return;
        }

        if ($action === 'start') {
            self::get_started();
            wp_safe_redirect(Tools_Page::page_url());
            exit;
        }

        if ($action === 'reopen') {
            self::reopen();
            wp_safe_redirect(Tools_Page::page_url());
            exit;
        }

        self::dismiss_banner();
        wp_safe_redirect(remove_query_arg(['ns_onboarding', '_wpnonce']));
        exit;
    }

    /**
     * Point new installs at the setup area; on Schema pages the panel takes over.
     */
    public function render_banner(): void {
        if (!Access::can_manage_schema() || !self::show_banner() || $this->is_schema_admin_screen()) {
            return;
        }

        $start_url = wp_nonce_url(
            Tools_Page::page_url(['ns_onboarding' => 'start']),
            'ns_onboarding_banner'
        );
        $dismiss_url = wp_nonce_url(
            add_query_arg('ns_onboarding', 'dismiss_banner'),
            'ns_onboarding_banner'
        );
        ?>
        <div class="notice notice-success ns-onboarding-banner" id="ns-onboarding-banner">
            <div class="ns-onboarding-banner__inner">
                <p class="ns-onboarding-banner__message">
                    <?php esc_html_e('Noble Schema activated successfully. Set up your schema to get started.', 'noble-schema'); ?>
                </p>
                <p class="ns-onboarding-banner__actions">
                    <a class="button button-primary" href="<?php echo esc_url($start_url); ?>">
                        <?php esc_html_e('Get started', 'noble-schema'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url($dismiss_url); ?>">
                        <?php esc_html_e('Dismiss', 'noble-schema'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    public function render_panel(): void {
        if (!Access::can_manage_schema() || !self::is_active_ui() || !$this->is_schema_admin_screen()) {
            return;
        }

        $state = self::get_state();
        if ($state === null) {
            return;
        }

        include dirname(__DIR__) . '/views/partials/onboarding-panel.php';
    }

    private function is_schema_admin_hook(string $hook): bool {
        return in_array($hook, [
            'settings_page_schema-config',
            'tools_page_schema-tools',
            'toplevel_page_noble-schema-settings',
        ], true);
    }

    private function is_schema_admin_screen(): bool {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        return in_array($screen->id, [
            'settings_page_schema-config',
            'tools_page_schema-tools',
            'toplevel_page_noble-schema-settings',
        ], true);
    }

    /**
     * @return array{
     *   status:string,
     *   path:?string,
     *   steps:array<string,array{completed:bool,manual:bool}>,
     *   show_banner:bool
     * }
     */
    public static function default_state(): array {
        $steps = [];
        foreach (self::STEP_KEYS as $key) {
            $steps[$key] = ['completed' => false, 'manual' => false];
        }

        return [
            'status'      => self::STATUS_ACTIVE,
            'path'        => null,
            'steps'       => $steps,
            'show_banner' => true,
        ];
    }

    public static function seed_on_activate(): void {
        self::save_state(self::default_state());
    }

    /**
     * @return array{
     *   status:string,
     *   path:?string,
     *   steps:array<string,array{completed:bool,manual:bool}>,
     *   show_banner:bool
     * }|null
     */
    public static function get_state(): ?array {
        $raw = get_option(self::OPT_STATE, null);
        if ($raw === null || $raw === false || !is_array($raw)) {
            return null;
        }

        return self::normalize_state($raw);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{
     *   status:string,
     *   path:?string,
     *   steps:array<string,array{completed:bool,manual:bool}>,
     *   show_banner:bool
     * }
     */
    public static function normalize_state(array $raw): array {
        $base = self::default_state();
        $status = (string) ($raw['status'] ?? $base['status']);
        if (!in_array($status, [
            self::STATUS_ACTIVE,
            self::STATUS_MINIMIZED,
            self::STATUS_CELEBRATING,
            self::STATUS_DISMISSED,
            self::STATUS_COMPLETED,
        ], true)) {
            $status = self::STATUS_ACTIVE;
        }

        $path = $raw['path'] ?? null;
        if ($path !== null && $path !== '') {
            $path = sanitize_key((string) $path);
            if (!in_array($path, [self::PATH_IMPORT, self::PATH_CHECKLIST], true)) {
                $path = null;
            }
        } else {
            $path = null;
        }

        $steps_in = is_array($raw['steps'] ?? null) ? $raw['steps'] : [];
        $steps = [];
        foreach (self::STEP_KEYS as $key) {
            $row = is_array($steps_in[$key] ?? null) ? $steps_in[$key] : [];
            $steps[$key] = [
                'completed' => !empty($row['completed']),
                'manual'    => !empty($row['manual']),
            ];
        }

        return [
            'status'      => $status,
            'path'        => $path,
            'steps'       => $steps,
            'show_banner' => !empty($raw['show_banner']),
        ];
    }

    /**
     * @param array{
     *   status:string,
     *   path:?string,
     *   steps:array<string,array{completed:bool,manual:bool}>,
     *   show_banner:bool
     * } $state
     */
    public static function save_state(array $state): void {
        update_option(self::OPT_STATE, self::normalize_state($state), false);
    }

    public static function is_active_ui(): bool {
        $state = self::get_state();
        if ($state === null) {
            return false;
        }

        return in_array($state['status'], [
            self::STATUS_ACTIVE,
            self::STATUS_MINIMIZED,
            self::STATUS_CELEBRATING,
        ], true);
    }

    public static function show_tools_card(): bool {
        $state = self::get_state();
        if ($state === null) {
            return false;
        }

        return in_array($state['status'], [self::STATUS_DISMISSED, self::STATUS_COMPLETED], true);
    }

    public static function show_banner(): bool {
        $state = self::get_state();
        return $state !== null && !empty($state['show_banner']);
    }

    /**
     * @param array{
     *   status:string,
     *   path:?string,
     *   steps:array<string,array{completed:bool,manual:bool}>,
     *   show_banner:bool
     * } $state
     */
    public static function completed_step_count(array $state): int {
        $count = 0;
        foreach (self::STEP_KEYS as $key) {
            if (!empty($state['steps'][$key]['completed'])) {
                $count++;
            }
        }

        return $count;
    }

    public static function set_path(string $path): void {
        $state = self::get_state() ?? self::default_state();
        $path = sanitize_key($path);

        // Empty / "question" returns to the initial import-vs-guide prompt.
        if ($path === '' || $path === 'question') {
            $state['path'] = null;
            if ($state['status'] === self::STATUS_DISMISSED || $state['status'] === self::STATUS_COMPLETED) {
                $state['status'] = self::STATUS_ACTIVE;
            }
            self::save_state($state);
            return;
        }

        if (!in_array($path, [self::PATH_IMPORT, self::PATH_CHECKLIST], true)) {
            return;
        }
        $state['path'] = $path;
        if ($state['status'] === self::STATUS_DISMISSED || $state['status'] === self::STATUS_COMPLETED) {
            $state['status'] = self::STATUS_ACTIVE;
        }
        self::save_state($state);
    }

    /** Return to the initial path question without clearing checklist progress. */
    public static function clear_path(): void {
        self::set_path('');
    }

    public static function minimize(): void {
        $state = self::get_state();
        if ($state === null) {
            return;
        }
        if (!in_array($state['status'], [self::STATUS_ACTIVE, self::STATUS_MINIMIZED], true)) {
            return;
        }
        $state['status'] = self::STATUS_MINIMIZED;
        self::save_state($state);
    }

    public static function restore(): void {
        $state = self::get_state();
        if ($state === null) {
            return;
        }
        if ($state['status'] !== self::STATUS_MINIMIZED) {
            return;
        }
        $state['status'] = self::STATUS_ACTIVE;
        self::save_state($state);
    }

    public static function dismiss(): void {
        $state = self::get_state();
        if ($state === null) {
            return;
        }
        $state['status'] = self::STATUS_DISMISSED;
        $state['show_banner'] = false;
        self::save_state($state);
    }

    public static function reopen(): void {
        $state = self::get_state();
        if ($state === null) {
            return;
        }
        $state['status'] = self::STATUS_ACTIVE;
        $state['show_banner'] = false;
        self::save_state($state);
    }

    public static function dismiss_banner(): void {
        $state = self::get_state();
        if ($state === null) {
            return;
        }
        $state['show_banner'] = false;
        self::save_state($state);
    }

    public static function get_started(): void {
        $state = self::get_state();
        if ($state === null) {
            return;
        }
        if ($state['status'] === self::STATUS_MINIMIZED) {
            $state['status'] = self::STATUS_ACTIVE;
        } elseif (in_array($state['status'], [self::STATUS_DISMISSED, self::STATUS_COMPLETED], true)) {
            $state['status'] = self::STATUS_ACTIVE;
        }
        $state['show_banner'] = false;
        self::save_state($state);
    }

    public static function mark_step_complete(string $step): void {
        $state = self::get_state();
        if ($state === null) {
            return;
        }
        $step = sanitize_key($step);
        if (!in_array($step, self::STEP_KEYS, true)) {
            return;
        }
        $state['steps'][$step]['completed'] = true;
        $state['steps'][$step]['manual'] = true;
        $state = self::maybe_complete_checklist($state);
        self::save_state($state);
    }

    /**
     * After a successful import: re-evaluate checklist progress.
     * Remaining tasks → show the checklist; everything done → celebration.
     */
    public static function complete_import_path(): void {
        $state = self::get_state();
        if ($state === null) {
            return;
        }
        if ($state['path'] !== self::PATH_IMPORT) {
            return;
        }
        if (!in_array($state['status'], [self::STATUS_ACTIVE, self::STATUS_MINIMIZED], true)) {
            return;
        }

        $state['path'] = self::PATH_CHECKLIST;
        $state['status'] = self::STATUS_ACTIVE;
        $state['show_banner'] = false;
        self::save_state($state);

        self::merge_auto_completion(null);

        $state = self::get_state();
        if ($state === null) {
            return;
        }

        // Celebrate when import (plus prior progress) covers every checklist step.
        if (self::completed_step_count($state) >= count(self::STEP_KEYS)) {
            $state['status'] = self::STATUS_CELEBRATING;
            $state['show_banner'] = false;
            self::save_state($state);
        }
    }

    /**
     * Persist completed after the celebration screen has been shown once.
     */
    public static function finalize_celebration(): void {
        $state = self::get_state();
        if ($state === null || ($state['status'] ?? '') !== self::STATUS_CELEBRATING) {
            return;
        }
        $state['status'] = self::STATUS_COMPLETED;
        $state['show_banner'] = false;
        self::save_state($state);
    }

    public static function touch_settings(): void {
        update_option(self::OPT_SETTINGS_TOUCHED, '1', false);
    }

    /**
     * @return array{
     *   status:string,
     *   path:?string,
     *   steps:array<string,array{completed:bool,manual:bool}>,
     *   show_banner:bool
     * }
     */
    public static function merge_auto_completion(?string $current_tool = null): array {
        $state = self::get_state();
        if ($state === null) {
            return self::default_state();
        }

        $changed = false;

        if ((string) get_option(self::OPT_SETTINGS_TOUCHED, '') === '1'
            && empty($state['steps']['settings']['completed'])
        ) {
            $state['steps']['settings']['completed'] = true;
            $changed = true;
        }

        if (self::custom_template_count() > 0 && empty($state['steps']['templates']['completed'])) {
            $state['steps']['templates']['completed'] = true;
            $changed = true;
        }

        if (self::config_count() > 0 && empty($state['steps']['configs']['completed'])) {
            $state['steps']['configs']['completed'] = true;
            $changed = true;
        }

        if ($current_tool === 'viewer' && empty($state['steps']['viewer']['completed'])) {
            $state['steps']['viewer']['completed'] = true;
            $changed = true;
        }

        if ($changed) {
            $state = self::maybe_complete_checklist($state);
            self::save_state($state);
        }

        return self::get_state() ?? $state;
    }

    /**
     * Payload for wp_localize_script under key `onboarding`.
     *
     * @return array<string, mixed>
     */
    public static function localize_payload(): array {
        $state = self::get_state();
        $enabled = $state !== null && (
            self::is_active_ui()
            || self::show_banner()
            || self::show_tools_card()
        );

        return [
            'enabled' => $enabled,
            'activeUi' => self::is_active_ui(),
            'showBanner' => self::show_banner(),
            'showToolsCard' => self::show_tools_card(),
            'state' => $state,
            'nonce' => function_exists('wp_create_nonce') ? wp_create_nonce('ns_onboarding') : '',
            'urls' => [
                'settings'  => Settings_Page::page_url(),
                'templates' => Tools_Page::page_url(['tool' => 'storefront']),
                'configs'   => Menu::page_url(['action' => 'add']),
                'viewer'    => Tools_Page::page_url(['tool' => 'checker']),
                'import'    => Tools_Page::page_url(['tool' => 'transfer', 'transfer_tab' => 'import']),
                'tools'     => Tools_Page::page_url(),
            ],
            'i18n' => [
                'dismissTitle'   => __('Dismiss setup guide?', 'noble-schema'),
                'dismissBody'    => __('You can reopen it anytime from Schema Tools.', 'noble-schema'),
                'dismissConfirm' => __('Dismiss', 'noble-schema'),
                'markComplete'   => __('Mark complete', 'noble-schema'),
                'progress'       => __(' %1$d of %2$d complete', 'noble-schema'),
                'setup'          => __('Setup', 'noble-schema'),
                'title'          => __('Getting started', 'noble-schema'),
                'completeTitle'  => __('You’re all set!', 'noble-schema'),
                'completeBody'   => __('Noble Schema is ready to go. This guide will close on your next visit.', 'noble-schema'),
            ],
        ];
    }

    /**
     * @param array{
     *   status:string,
     *   path:?string,
     *   steps:array<string,array{completed:bool,manual:bool}>,
     *   show_banner:bool
     * } $state
     * @return array{
     *   status:string,
     *   path:?string,
     *   steps:array<string,array{completed:bool,manual:bool}>,
     *   show_banner:bool
     * }
     */
    private static function maybe_complete_checklist(array $state): array {
        if ($state['path'] !== self::PATH_CHECKLIST) {
            return $state;
        }
        if (!in_array($state['status'], [self::STATUS_ACTIVE, self::STATUS_MINIMIZED], true)) {
            return $state;
        }
        if (self::completed_step_count($state) >= count(self::STEP_KEYS)) {
            $state['status'] = self::STATUS_CELEBRATING;
            $state['show_banner'] = false;
        }

        return $state;
    }

    private static function custom_template_count(): int {
        if (isset($GLOBALS['noble_schema_test_template_count'])) {
            return (int) $GLOBALS['noble_schema_test_template_count'];
        }

        try {
            $plugin_dir = dirname(__DIR__, 2);
            $listed = (new Template_Factory($plugin_dir . '/templates'))->list_custom();
            return is_array($listed) ? count($listed) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function config_count(): int {
        if (isset($GLOBALS['noble_schema_test_config_count'])) {
            return (int) $GLOBALS['noble_schema_test_config_count'];
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            return 0;
        }

        $table = $wpdb->prefix . 'ns_configs';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return (int) $count;
    }
}
