<?php

declare(strict_types=1);

namespace NobleSchemaLite;

final class Meta_Box {
    public function register(): void {
        add_action('add_meta_boxes', [$this, 'add_box']);
        add_action('save_post', [$this, 'save'], 10, 2);
    }

    public function add_box(): void {
        $types = get_post_types(['public' => true], 'names');
        foreach ($types as $type) {
            add_meta_box(
                'ns-lite-schema',
                __('Schema JSON-LD', 'noble-schema-lite'),
                [$this, 'render'],
                $type,
                'normal',
                'default'
            );
        }
    }

    public function render(\WP_Post $post): void {
        $value = get_post_meta($post->ID, NS_LITE_META_KEY, true);
        $value = is_string($value) ? $value : '';
        wp_nonce_field('ns_lite_schema_meta', 'ns_lite_schema_nonce');
        echo '<p class="description">' . esc_html__(
            'Raw JSON-LD output for this page. Printed in the document head when non-empty.',
            'noble-schema-lite'
        ) . '</p>';
        printf(
            '<textarea class="widefat code" rows="14" name="ns_lite_schema" id="ns_lite_schema" style="font-family:monospace;">%s</textarea>',
            esc_textarea($value)
        );
    }

    public function save(int $post_id, \WP_Post $post): void {
        if (!isset($_POST['ns_lite_schema_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['ns_lite_schema_nonce'])), 'ns_lite_schema_meta')
        ) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw = isset($_POST['ns_lite_schema']) ? wp_unslash((string) $_POST['ns_lite_schema']) : '';
        $raw = trim($raw);
        if ($raw === '') {
            delete_post_meta($post_id, NS_LITE_META_KEY);
            return;
        }

        update_post_meta($post_id, NS_LITE_META_KEY, $raw);
    }
}
