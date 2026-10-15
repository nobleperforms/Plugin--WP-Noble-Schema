<?php

declare(strict_types=1);

use NobleSchema\Offboard\Offboard;

add_action('wp_ajax_ns_schema_tools_offboard_start', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $confirm = isset($_POST['confirm']) ? (string) wp_unslash((string) $_POST['confirm']) : '';
    if ($confirm !== Offboard::CONFIRM_PHRASE) {
        wp_send_json_error([
            'message' => __('Type OFFBOARD to confirm offboarding.', 'noble-schema'),
        ], 400);
    }

    if (!Offboard::plugins_dir_writable()) {
        wp_send_json_error([
            'message' => __('Offboarding requires a writable wp-content/plugins directory.', 'noble-schema'),
        ], 400);
    }

    try {
        $plugin_file = \NobleSchema\plugin()->plugin_file();
        $result = (new Offboard())->start($plugin_file);
        wp_send_json_success([
            'message'  => __('Schema Lite installed. Redirecting…', 'noble-schema'),
            'redirect' => $result['redirect'],
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Offboarding failed: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});
