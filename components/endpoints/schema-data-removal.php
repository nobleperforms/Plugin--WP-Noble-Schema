<?php

declare(strict_types=1);

use NobleSchema\Removal\Data_Remover;

add_action('wp_ajax_ns_schema_tools_data_removal', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $confirm = isset($_POST['confirm']) ? (string) wp_unslash((string) $_POST['confirm']) : '';
    if ($confirm !== Data_Remover::CONFIRM_PHRASE) {
        wp_send_json_error([
            'message' => __('Type DELETE to confirm data removal.', 'noble-schema'),
        ], 400);
    }

    $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash((string) $_POST['scope'])) : '';

    try {
        $result = (new Data_Remover())->purge($scope);
        wp_send_json_success($result);
    } catch (\InvalidArgumentException $e) {
        wp_send_json_error(['message' => $e->getMessage()], 400);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Data removal failed: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});
