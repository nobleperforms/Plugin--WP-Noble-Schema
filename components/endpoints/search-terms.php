<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

add_action('wp_ajax_ns_search_terms', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_search_terms', 'nonce');

    $taxonomy = isset($_GET['taxonomy']) ? sanitize_key(wp_unslash((string) $_GET['taxonomy'])) : '';
    $search   = isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '';
    $term_id  = isset($_GET['term_id']) ? (int) $_GET['term_id'] : 0;

    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
        wp_send_json_success(['terms' => []]);
    }

    if ($term_id > 0) {
        $term = get_term($term_id, $taxonomy);
        if ($term && !is_wp_error($term)) {
            wp_send_json_success([
                'terms' => [[
                    'value' => (string) (int) $term->term_id,
                    'label' => html_entity_decode((string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ]],
            ]);
        }
        wp_send_json_success([
            'terms' => [[
                'value' => (string) $term_id,
                'label' => \NobleSchema\Targeting\Taxonomy_Rule::term_label($term_id, $taxonomy),
            ]],
        ]);
    }

    $args = [
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => 50,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ];

    if ($search !== '') {
        $args['search'] = $search;
    }

    $terms = get_terms($args);
    if (is_wp_error($terms) || !is_array($terms)) {
        wp_send_json_success(['terms' => []]);
    }

    $payload = array_map(static function ($term) {
        return [
            'value' => (string) (int) $term->term_id,
            'label' => html_entity_decode((string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }, $terms);

    wp_send_json_success(['terms' => array_values($payload)]);
});
