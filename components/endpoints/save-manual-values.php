<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

use NobleSchema\Admin\Manual_Values_Meta_Box;
use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Db\Manual_Value_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\Targeting\Matcher;
use NobleSchema\Templates\Template_Loader;

add_action('save_post', function ($post_id, $post, $update): void {
    $post_id = (int) $post_id;

    if ($post_id <= 0 || wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    if (!isset($_POST[Manual_Values_Meta_Box::NONCE_FIELD])) {
        return;
    }

    if (!wp_verify_nonce(
        sanitize_text_field(wp_unslash((string) $_POST[Manual_Values_Meta_Box::NONCE_FIELD])),
        Manual_Values_Meta_Box::NONCE_ACTION
    )) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $plugin_dir = dirname(__DIR__, 2);
    $meta_box   = new Manual_Values_Meta_Box(
        new Config_Repository(),
        new Target_Repository(),
        new Field_Repository(),
        new Manual_Value_Repository(),
        new Template_Loader($plugin_dir . '/templates'),
        new Matcher(),
    );

    $meta_box->save_from_request($post_id, wp_unslash($_POST));
}, 10, 3);
