<?php

declare(strict_types=1);

use NobleSchema\Db\Config_Repository;
use NobleSchema\Transfer\Pack;

add_action('wp_ajax_ns_schema_tools_transfer_export', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash((string) $_POST['scope'])) : 'full';
    $config_id = isset($_POST['config_id']) ? (int) $_POST['config_id'] : 0;

    try {
        $pack = new Pack();
        if ($scope === 'settings') {
            $payload = $pack->export_settings();
            $filename = 'noble-schema-settings-' . gmdate('Y-m-d') . '.json';
        } elseif ($scope === 'config') {
            if ($config_id <= 0) {
                wp_send_json_error(['message' => __('Select a schema config to export.', 'noble-schema')], 400);
            }
            $payload = $pack->export_config($config_id);
            $filename = 'noble-schema-config-' . $config_id . '-' . gmdate('Y-m-d') . '.json';
        } else {
            $payload = $pack->export_full();
            $filename = 'noble-schema-export-' . gmdate('Y-m-d') . '.json';
        }

        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException(__('Could not encode export.', 'noble-schema'));
        }

        wp_send_json_success([
            'filename' => $filename,
            'json'     => $json . "\n",
            'summary'  => [
                'scope'      => $payload['scope'],
                'templates'  => count($payload['custom_templates'] ?? []),
                'configs'    => count($payload['configs'] ?? []),
                'settings'   => count($payload['settings'] ?? []),
            ],
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Export failed: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});

add_action('wp_ajax_ns_schema_tools_transfer_preview', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $raw = '';
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
        $raw = (string) file_get_contents((string) $_FILES['file']['tmp_name']);
    } elseif (isset($_POST['json'])) {
        $raw = (string) wp_unslash((string) $_POST['json']);
    }

    if (trim($raw) === '') {
        wp_send_json_error(['message' => __('Upload a Noble Schema export JSON file.', 'noble-schema')], 400);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        wp_send_json_error(['message' => __('File is not valid JSON.', 'noble-schema')], 400);
    }

    try {
        $pack_service = new Pack();
        $normalized = $pack_service->normalize($decoded);
        $preview = $pack_service->preview($normalized);

        $token = wp_generate_password(20, false, false);
        $user_id = get_current_user_id();
        set_transient(
            'ns_import_' . $user_id . '_' . $token,
            $normalized,
            HOUR_IN_SECONDS
        );

        wp_send_json_success([
            'token'   => $token,
            'preview' => $preview,
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Could not read export: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 400);
    }
});

add_action('wp_ajax_ns_schema_tools_transfer_apply', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
    if ($token === '') {
        wp_send_json_error(['message' => __('Missing import session. Upload the file again.', 'noble-schema')], 400);
    }

    $user_id = get_current_user_id();
    $key = 'ns_import_' . $user_id . '_' . $token;
    $normalized = get_transient($key);
    if (!is_array($normalized)) {
        wp_send_json_error(['message' => __('Import session expired. Upload the file again.', 'noble-schema')], 400);
    }

    $decisions_raw = isset($_POST['decisions']) ? wp_unslash((string) $_POST['decisions']) : '{}';
    $decisions = json_decode($decisions_raw, true);
    if (!is_array($decisions)) {
        $decisions = [];
    }

    try {
        $result = (new Pack())->apply($normalized, $decisions);
        delete_transient($key);
        \NobleSchema\Admin\Onboarding::complete_import_path();

        wp_send_json_success([
            'result'     => $result,
            'message'    => __('Import complete.', 'noble-schema'),
            'onboarding' => function_exists('ns_onboarding_ajax_state')
                ? ns_onboarding_ajax_state()
                : [
                    'state'         => \NobleSchema\Admin\Onboarding::get_state(),
                    'activeUi'      => \NobleSchema\Admin\Onboarding::is_active_ui(),
                    'showBanner'    => \NobleSchema\Admin\Onboarding::show_banner(),
                    'showToolsCard' => \NobleSchema\Admin\Onboarding::show_tools_card(),
                ],
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Import failed: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});

add_action('wp_ajax_ns_schema_tools_transfer_configs', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $rows = (new Config_Repository())->all(['orderby' => 'name', 'order' => 'ASC']);
    $configs = array_map(static function ($row): array {
        return [
            'id'     => (int) ($row->id ?? 0),
            'name'   => (string) ($row->name ?? ''),
            'status' => (string) ($row->status ?? ''),
        ];
    }, $rows);

    wp_send_json_success(['configs' => $configs]);
});
