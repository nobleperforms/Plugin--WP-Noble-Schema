<?php

declare(strict_types=1);

use NobleSchema\Tools\Log;

add_action('wp_ajax_ns_schema_tools_logs_query', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    if (!Log::enabled()) {
        wp_send_json_success([
            'enabled'  => false,
            'rows'     => [],
            'has_more' => false,
            'has_any'  => false,
            'filters'  => [
                'levels'     => [],
                'categories' => [],
            ],
        ]);
    }

    $level = isset($_REQUEST['level']) ? sanitize_key(wp_unslash((string) $_REQUEST['level'])) : '';
    $category = isset($_REQUEST['category']) ? sanitize_key(wp_unslash((string) $_REQUEST['category'])) : '';
    $after_id = isset($_REQUEST['after_id']) ? (int) $_REQUEST['after_id'] : 0;
    $before_id = isset($_REQUEST['before_id']) ? (int) $_REQUEST['before_id'] : 0;
    $date_from = Log::sanitize_date_ymd(isset($_REQUEST['date_from']) ? wp_unslash((string) $_REQUEST['date_from']) : '');
    $date_to = Log::sanitize_date_ymd(isset($_REQUEST['date_to']) ? wp_unslash((string) $_REQUEST['date_to']) : '');
    $limit = isset($_REQUEST['limit']) ? (int) $_REQUEST['limit'] : 50;
    $limit = max(1, min(200, $limit));

    $rows = Log::query([
        'level'     => $level,
        'category'  => $category,
        'after_id'  => $after_id,
        'before_id' => $before_id,
        'date_from' => $date_from,
        'date_to'   => $date_to,
        'limit'     => $limit + 1,
    ]);

    $has_more = count($rows) > $limit;
    if ($has_more) {
        $rows = array_slice($rows, 0, $limit);
    }

    wp_send_json_success([
        'enabled'  => true,
        'rows'     => $rows,
        'has_more' => $has_more,
        'has_any'  => $rows !== [] || Log::has_any(),
        'filters'  => Log::filter_options(),
    ]);
});

add_action('wp_ajax_ns_schema_tools_logs_purge', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    if (!Log::enabled()) {
        wp_send_json_error([
            'message' => __('System logging is turned off.', 'noble-schema'),
        ], 400);
    }

    $deleted = Log::purge_all();

    wp_send_json_success([
        'deleted' => $deleted,
        'message' => sprintf(
            /* translators: %d: number of deleted log rows */
            _n('%d log entry deleted.', '%d log entries deleted.', $deleted, 'noble-schema'),
            $deleted
        ),
    ]);
});
