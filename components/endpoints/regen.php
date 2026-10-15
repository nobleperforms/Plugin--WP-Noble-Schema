<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

use NobleSchema\Generator\Regen_Queue;

add_action('admin_post_ns_regen_config', function (): void {
    \NobleSchema\Admin\Access::require_manage_schema_page();

    $config_id = isset($_GET['config_id']) ? (int) $_GET['config_id'] : 0;
    check_admin_referer('ns_regen_config_' . $config_id);

    if ($config_id > 0) {
        (new Regen_Queue())->enqueue_for_config($config_id);
    }

    $args = [
        'page'      => 'schema-config',
        'action'    => 'edit',
        'config_id' => $config_id,
        'ns_notice' => 'success',
        'ns_message' => rawurlencode(__('Regeneration started for affected posts.', 'noble-schema')),
    ];

    if ((new Regen_Queue())->is_pending()) {
        $args['ns_regen_modal'] = '1';
    }

    wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
    exit;
});

add_action('wp_ajax_ns_regen_status', function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_regen', 'nonce');

    wp_send_json_success((new Regen_Queue())->get_status());
});

add_action('wp_ajax_ns_regen_process_batch', function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_regen', 'nonce');

    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    @set_time_limit(60);

    try {
        $queue = new Regen_Queue();
        // Skip work if another window already cancelled; still return cancelled status.
        if ($queue->is_pending() && !$queue->is_cancelled()) {
            $queue->process_batch();
        }
        wp_send_json_success($queue->get_status());
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Regeneration batch failed: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});

add_action('wp_ajax_ns_regen_cancel', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_regen', 'nonce');

    $status = (new Regen_Queue())->cancel();
    $status['message'] = __('Schema update cancelled.', 'noble-schema');

    wp_send_json_success($status);
});

add_action('wp_ajax_ns_regen_ack_cancelled', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_regen', 'nonce');

    (new Regen_Queue())->acknowledge_cancelled();
    wp_send_json_success(['ok' => true]);
});
