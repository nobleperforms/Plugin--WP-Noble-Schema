<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

use NobleSchema\Admin\Schema_Inspector;
use NobleSchema\Schema\Schema_Validator;

add_action('wp_ajax_ns_schema_tools_inspect', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_schema_tools', 'nonce');

    $post_id = isset($_REQUEST['post_id']) ? (int) $_REQUEST['post_id'] : 0;
    if ($post_id <= 0) {
        wp_send_json_error(['message' => __('Invalid post ID.', 'noble-schema')], 400);
    }

    $report = (new Schema_Inspector())->inspect($post_id);
    if ($report === null) {
        wp_send_json_error(['message' => __('Post not found.', 'noble-schema')], 404);
    }

    $report['validation'] = Schema_Validator::validate_snapshot($report['snapshot'] ?? null);

    wp_send_json_success(['report' => $report]);
});

add_action('wp_ajax_ns_schema_tools_viewer', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_schema_tools', 'nonce');

    $source = isset($_REQUEST['source']) ? sanitize_key(wp_unslash((string) $_REQUEST['source'])) : 'stored';
    if (!in_array($source, ['stored', 'live'], true)) {
        $source = 'stored';
    }

    $resolved = \NobleSchema\Debug\Debug_Context_Resolver::from_request([
        'url'           => isset($_REQUEST['url']) ? wp_unslash((string) $_REQUEST['url']) : '',
        'context'       => isset($_REQUEST['context']) ? wp_unslash((string) $_REQUEST['context']) : '',
        'post_id'       => isset($_REQUEST['post_id']) ? (int) $_REQUEST['post_id'] : 0,
        'archive_key'   => isset($_REQUEST['archive_key']) ? wp_unslash((string) $_REQUEST['archive_key']) : '',
        'specialty_key' => isset($_REQUEST['specialty_key']) ? wp_unslash((string) $_REQUEST['specialty_key']) : '',
    ]);

    if (!$resolved['ok'] || !is_array($resolved['context'] ?? null)) {
        $status = ($resolved['code'] ?? '') === 'not_found' ? 404 : 400;
        wp_send_json_error(['message' => $resolved['message'] ?: __('Could not resolve debug target.', 'noble-schema')], $status);
    }

    if ($source === 'live' && function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
        @set_time_limit(60);
    }

    try {
        $payload = (new Schema_Inspector())->viewer_for_context($resolved['context'], $source);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Could not load schema: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }

    wp_send_json_success($payload);
});

add_action('wp_ajax_ns_schema_tools_resolve', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $resolved = \NobleSchema\Debug\Debug_Context_Resolver::from_request([
        'url'           => isset($_REQUEST['url']) ? wp_unslash((string) $_REQUEST['url']) : '',
        'context'       => isset($_REQUEST['context']) ? wp_unslash((string) $_REQUEST['context']) : '',
        'post_id'       => isset($_REQUEST['post_id']) ? (int) $_REQUEST['post_id'] : 0,
        'archive_key'   => isset($_REQUEST['archive_key']) ? wp_unslash((string) $_REQUEST['archive_key']) : '',
        'specialty_key' => isset($_REQUEST['specialty_key']) ? wp_unslash((string) $_REQUEST['specialty_key']) : '',
    ]);

    if (!$resolved['ok']) {
        $status = ($resolved['code'] ?? '') === 'off_site' ? 400 : 400;
        wp_send_json_error([
            'message' => $resolved['message'],
            'code'    => $resolved['code'],
        ], $status);
    }

    wp_send_json_success(['context' => $resolved['context']]);
});

add_action('wp_ajax_ns_schema_tools_viewer_site', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    @set_time_limit(120);

    $args = [
        'page'         => isset($_REQUEST['page_num']) ? (int) $_REQUEST['page_num'] : 1,
        'per_page'     => isset($_REQUEST['per_page']) ? (int) $_REQUEST['per_page'] : 25,
        'tier'         => isset($_REQUEST['tier']) ? sanitize_key(wp_unslash((string) $_REQUEST['tier'])) : '',
        'post_type'    => isset($_REQUEST['post_type']) ? sanitize_key(wp_unslash((string) $_REQUEST['post_type'])) : '',
        'invalid_only' => !empty($_REQUEST['invalid_only']),
        'issue_reason' => isset($_REQUEST['issue_reason']) ? sanitize_key(wp_unslash((string) $_REQUEST['issue_reason'])) : '',
    ];

    try {
        $summary = (new \NobleSchema\Coverage\Coverage_Aggregator())->summarize($args);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Site coverage failed: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }

    wp_send_json_success(['summary' => $summary]);
});

add_action('wp_ajax_ns_schema_tools_viewer_scan_start', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');
    wp_send_json_success(['scan' => \NobleSchema\Coverage\Coverage_Scan_Queue::start()]);
});

add_action('wp_ajax_ns_schema_tools_viewer_scan_process', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    @set_time_limit(60);

    wp_send_json_success(['scan' => \NobleSchema\Coverage\Coverage_Scan_Queue::process_batch()]);
});

add_action('wp_ajax_ns_schema_tools_viewer_scan_status', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');
    wp_send_json_success(['scan' => \NobleSchema\Coverage\Coverage_Scan_Queue::get_status()]);
});

add_action('wp_ajax_ns_schema_tools_viewer_scan_cancel', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');
    \NobleSchema\Coverage\Coverage_Scan_Queue::cancel();
    wp_send_json_success(['scan' => \NobleSchema\Coverage\Coverage_Scan_Queue::get_status()]);
});

add_action('wp_ajax_ns_schema_tools_regenerate', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_schema_tools', 'nonce');

    $post_id = isset($_REQUEST['post_id']) ? (int) $_REQUEST['post_id'] : 0;
    if ($post_id <= 0) {
        wp_send_json_error(['message' => __('Invalid post ID.', 'noble-schema')], 400);
    }

    if (!get_post($post_id) instanceof \WP_Post) {
        wp_send_json_error(['message' => __('Post not found.', 'noble-schema')], 404);
    }

    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    @set_time_limit(60);

    $inspector = new Schema_Inspector();
    $before    = $inspector->snapshot_payload($post_id);

    try {
        \NobleSchema\plugin()->generator()->generate_for_post($post_id);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Regeneration failed: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }

    $after  = $inspector->snapshot_payload($post_id);
    $report = $inspector->inspect($post_id);
    $changed = schema_tools_snapshot_changed($before, $after);

    wp_send_json_success([
        'before'  => $before,
        'after'   => $after,
        'changed' => $changed,
        'report'  => $report,
        'message' => $changed
            ? __('Schema regenerated. Changes detected.', 'noble-schema')
            : __('Schema unchanged after regenerate.', 'noble-schema'),
    ]);
});

add_action('wp_ajax_ns_schema_tools_batch_preview', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_schema_tools', 'nonce');

    $targeting = schema_tools_parse_batch_targeting();
    $preview = (new \NobleSchema\Generator\Regen_Queue())->preview_targeting_count($targeting);
    wp_send_json_success($preview);
});

add_action('wp_ajax_ns_schema_tools_batch_regen', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_schema_tools', 'nonce');

    $targeting = schema_tools_parse_batch_targeting();

    if (
        $targeting['placement'] === 'targeted'
        && $targeting['post_types'] === []
        && $targeting['page_templates'] === []
        && $targeting['posts'] === []
        && $targeting['archives'] === []
    ) {
        wp_send_json_error([
            'message' => __('Select at least one target, or choose Global.', 'noble-schema'),
        ], 400);
    }

    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    @set_time_limit(120);

    try {
        $status = (new \NobleSchema\Generator\Regen_Queue())->enqueue_from_targeting($targeting);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Batch regeneration failed: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }

    wp_send_json_success([
        'regen'   => $status,
        'message' => __('Regeneration started.', 'noble-schema'),
    ]);
});

/**
 * @return array{placement:string,post_types:string[],page_templates:string[],blocks:string[],filter_groups:list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>,posts:int[],archives:string[]}
 */
function schema_tools_parse_batch_targeting(): array {
    $placement = isset($_POST['placement']) ? sanitize_key(wp_unslash((string) $_POST['placement'])) : 'targeted';
    if (!in_array($placement, ['global', 'targeted'], true)) {
        $placement = 'targeted';
    }

    $post_types = isset($_POST['post_types']) ? (array) wp_unslash($_POST['post_types']) : [];
    $page_templates = isset($_POST['page_templates']) ? (array) wp_unslash($_POST['page_templates']) : [];
    $posts = isset($_POST['posts']) ? (array) wp_unslash($_POST['posts']) : [];
    $archives = isset($_POST['archives']) ? (array) wp_unslash($_POST['archives']) : [];
    $specialties = isset($_POST['specialties']) ? (array) wp_unslash($_POST['specialties']) : [];

    $filter_groups_raw = isset($_POST['filter_groups']) ? (array) wp_unslash($_POST['filter_groups']) : [];
    $filter_groups = [];
    foreach ($filter_groups_raw as $group) {
        if (!is_array($group)) {
            continue;
        }
        $rules = [];
        foreach ($group as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $type = sanitize_key((string) ($rule['type'] ?? ''));
            if ($type === 'taxonomy') {
                $rules[] = [
                    'type'     => 'taxonomy',
                    'taxonomy' => sanitize_key((string) ($rule['taxonomy'] ?? '')),
                    'term_id'  => (int) ($rule['term_id'] ?? $rule['term'] ?? 0),
                    'operator' => sanitize_key((string) ($rule['operator'] ?? 'is')),
                ];
                continue;
            }
            if ($type === 'has_block') {
                $rules[] = [
                    'type'  => 'has_block',
                    'block' => sanitize_text_field((string) ($rule['block'] ?? '')),
                ];
                continue;
            }
            if ($type === 'post_meta') {
                $rules[] = [
                    'type'     => 'post_meta',
                    'key'      => (string) ($rule['key'] ?? ''),
                    'value'    => (string) ($rule['value'] ?? ''),
                    'operator' => sanitize_key((string) ($rule['operator'] ?? 'is')),
                ];
                continue;
            }
            if ($type === 'language') {
                $rules[] = [
                    'type'     => 'language',
                    'language' => (string) ($rule['language'] ?? ''),
                    'operator' => sanitize_key((string) ($rule['operator'] ?? 'is')),
                ];
            }
        }
        if ($rules !== []) {
            $filter_groups[] = $rules;
        }
    }

    return [
        'placement'      => $placement,
        'post_types'     => array_values(array_filter(array_map('sanitize_key', $post_types))),
        'page_templates' => array_values(array_filter(array_map('sanitize_text_field', $page_templates))),
        'blocks'         => [],
        'filter_groups'  => \NobleSchema\Generator\Regen_Queue::normalize_filter_groups($filter_groups),
        'posts'          => array_values(array_filter(array_map('intval', $posts))),
        'archives'       => array_values(array_filter(array_map('sanitize_text_field', $archives))),
        'specialties'    => array_values(array_filter(array_map(
            static function ($key) {
                $key = sanitize_key((string) $key);
                return \NobleSchema\Targeting\Specialty_Context::is_known($key) ? $key : '';
            },
            $specialties
        ))),
    ];
}

/**
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 */
function schema_tools_snapshot_changed(array $before, array $after): bool {
    if ((bool) ($before['present'] ?? false) !== (bool) ($after['present'] ?? false)) {
        return true;
    }
    if ((string) ($before['content_hash'] ?? '') !== (string) ($after['content_hash'] ?? '')) {
        return true;
    }
    if ((array) ($before['config_ids'] ?? []) !== (array) ($after['config_ids'] ?? [])) {
        return true;
    }
    if ((string) ($before['graph_json'] ?? '') !== (string) ($after['graph_json'] ?? '')) {
        return true;
    }

    return false;
}
