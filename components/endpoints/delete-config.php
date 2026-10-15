<?php

namespace NobleSchema\Endpoints;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\Generator\Regen_Queue;

add_action('admin_post_ns_delete_config', function (): void {
    \NobleSchema\Admin\Access::require_manage_schema_page();

    $id = isset($_GET['config_id']) ? (int) $_GET['config_id'] : 0;
    check_admin_referer('ns_delete_config_' . $id);

    $repo   = new Config_Repository();
    $config = $id > 0 ? $repo->find($id) : null;

    if ($config) {
        $targets = (new Target_Repository())->for_config($id);
        (new Regen_Queue())->enqueue_for_config_targets($config, $targets);
    }

    $deleted = $id > 0 && $repo->delete($id);
    if ($deleted && $id > 0) {
        (new \NobleSchema\Cache\Sitewide_Cache())->delete($id);
    }

    $args = [
        'page'       => 'schema-config',
        'ns_notice'  => $deleted ? 'success' : 'error',
        'ns_message' => rawurlencode(
            $deleted
                ? __('Config deleted.', 'noble-schema')
                : __('Could not delete config.', 'noble-schema')
        ),
    ];

    if ($deleted && (new Regen_Queue())->is_pending()) {
        $args['ns_regen_modal'] = '1';
    }

    wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
    exit;
});
