<?php

declare(strict_types=1);

use NobleSchema\Admin\Access;
use NobleSchema\Admin\Settings_Page;
use NobleSchema\Update\Updater;

add_action('wp_ajax_ns_schema_settings_check_updates', static function (): void {
    if (!Access::can_manage_schema() || !current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Forbidden.', 'noble-schema')], 403);
    }

    check_ajax_referer('ns_schema_settings', 'nonce');

    $settings = new Settings_Page(dirname(__DIR__, 2) . '/index.php');
    if (!$settings->updates_enabled()) {
        wp_send_json_error([
            'message' => __('Enable automatic update checks first.', 'noble-schema'),
            'status'  => Updater::last_check(),
        ]);
    }

    if ($settings->gh_username() === '' || $settings->gh_repo() === '') {
        wp_send_json_error([
            'message' => __('GitHub username and repository are required.', 'noble-schema'),
            'status'  => Updater::last_check(),
        ]);
    }

    // Clear cache and let the already-booted Updater (from Settings_Page) run.
    delete_site_transient('update_plugins');
    if (function_exists('wp_update_plugins')) {
        wp_update_plugins();
    }

    $status = Updater::last_check();
    if ($status === []) {
        wp_send_json_error([
            'message' => __('Update check did not run. Confirm updates are enabled and try again.', 'noble-schema'),
            'status'  => $status,
        ]);
    }

    if (!empty($status['ok'])) {
        wp_send_json_success([
            'message' => (string) ($status['message'] ?? __('Update check complete.', 'noble-schema')),
            'status'  => $status,
        ]);
    }

    wp_send_json_error([
        'message' => (string) ($status['message'] ?? __('Update check failed.', 'noble-schema')),
        'status'  => $status,
    ]);
});
