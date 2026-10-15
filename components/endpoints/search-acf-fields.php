<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

use NobleSchema\Mapping\Acf_Field_Catalog;

add_action('wp_ajax_ns_search_acf_fields', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_search_acf_fields', 'nonce');

    $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '';
    $fields = Acf_Field_Catalog::search($search, 50);

    wp_send_json_success(['fields' => array_values($fields)]);
});
