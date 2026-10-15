<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

add_action('wp_ajax_ns_search_posts', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_search_posts', 'nonce');

    $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '';
    $ids    = isset($_GET['ids']) ? (array) wp_unslash($_GET['ids']) : [];
    $ids    = array_values(array_filter(array_map('intval', $ids)));

    if ($ids !== []) {
        $posts = get_posts([
            'post_type'              => 'any',
            'post_status'            => ['publish', 'draft', 'pending', 'future', 'private'],
            'post__in'               => $ids,
            'orderby'                => 'post__in',
            'posts_per_page'         => count($ids),
            'suppress_filters'       => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        wp_send_json_success([
            'posts' => array_map('NobleSchema\\Endpoints\\ns_format_post_option', $posts),
        ]);
    }

    $post_types = array_values(get_post_types(['public' => true], 'names'));
    if ($post_types === []) {
        wp_send_json_success(['posts' => []]);
    }

    $args = [
        'post_type'              => $post_types,
        'post_status'            => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'         => 30,
        'orderby'                => 'modified',
        'order'                  => 'DESC',
        'suppress_filters'       => true,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ];

    if ($search !== '' && ctype_digit($search)) {
        $by_id = get_post((int) $search);
        $posts = [];
        if ($by_id instanceof \WP_Post && in_array($by_id->post_type, $post_types, true)) {
            $posts[] = $by_id;
        }
        // Also include title matches for numeric strings.
        $args['s'] = $search;
        $title_matches = get_posts($args);
        foreach ($title_matches as $post) {
            if ((int) $post->ID === (int) $search) {
                continue;
            }
            $posts[] = $post;
        }
        wp_send_json_success([
            'posts' => array_map('NobleSchema\\Endpoints\\ns_format_post_option', array_slice($posts, 0, 30)),
        ]);
    }

    if ($search !== '') {
        $args['s'] = $search;
    }

    $posts = get_posts($args);
    wp_send_json_success([
        'posts' => array_map('NobleSchema\\Endpoints\\ns_format_post_option', $posts),
    ]);
});

/**
 * @return array{value: string, label: string}
 */
function ns_format_post_option(\WP_Post $post): array {
    $type = get_post_type_object($post->post_type);
    $type_label = $type ? (string) ($type->labels->singular_name ?? $post->post_type) : $post->post_type;
    $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($title === '') {
        $title = sprintf(
            /* translators: %d: post ID */
            __('(no title) #%d', 'noble-schema'),
            (int) $post->ID
        );
    }

    return [
        'value' => (string) (int) $post->ID,
        'label' => sprintf('%s (%s · #%d)', $title, $type_label, (int) $post->ID),
    ];
}
