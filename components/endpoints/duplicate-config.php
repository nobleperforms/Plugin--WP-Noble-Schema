<?php

namespace NobleSchema\Endpoints;

use NobleSchema\Db\Config_Repository;

add_action('admin_post_ns_duplicate_config', function (): void {
    \NobleSchema\Admin\Access::require_manage_schema_page();

    $id = isset($_GET['config_id']) ? (int) $_GET['config_id'] : 0;
    check_admin_referer('ns_duplicate_config_' . $id);

    $repo   = new Config_Repository();
    $new_id = $id > 0 ? $repo->duplicate($id) : 0;

    $args = [
        'page'       => 'schema-config',
        'ns_notice'  => $new_id ? 'success' : 'error',
        'ns_message' => rawurlencode(
            $new_id
                ? __('Config duplicated.', 'noble-schema')
                : __('Could not duplicate config.', 'noble-schema')
        ),
    ];

    wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
    exit;
});
