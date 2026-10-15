<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

add_action('wp_ajax_ns_search_meta_keys', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_search_meta_keys', 'nonce');

    global $wpdb;

    $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '';
    $search = trim($search);

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
    $sql = "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
            WHERE meta_key NOT LIKE '\\_%%'";
    $params = [];

    if ($search !== '') {
        $sql .= ' AND meta_key LIKE %s';
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }

    $sql .= ' ORDER BY meta_key ASC LIMIT 50';

    if ($params !== []) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
        $sql = $wpdb->prepare($sql, ...$params);
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared when params present.
    $keys = $wpdb->get_col($sql);
    if (!is_array($keys)) {
        $keys = [];
    }

    $payload = [];
    foreach ($keys as $key) {
        $key = (string) $key;
        if ($key === '' || str_starts_with($key, '_')) {
            continue;
        }
        $payload[] = [
            'value' => $key,
            'label' => $key,
        ];
    }

    wp_send_json_success(['keys' => array_values($payload)]);
});
