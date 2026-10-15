<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Core_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        $field = (string) ($source_config['field'] ?? '');
        $post_id = (int) ($context['post_id'] ?? 0);
        $post = $context['post'] ?? null;

        if ($post === null && $post_id > 0) {
            $post = get_post($post_id);
        }

        return match ($field) {
            'title' => $post_id > 0 ? get_the_title($post_id) : null,
            'excerpt' => $post instanceof \WP_Post ? get_the_excerpt($post) : null,
            'permalink' => $post_id > 0 ? get_permalink($post_id) : null,
            'featured_image' => $this->featured_image_url($post_id),
            'author_name' => $post instanceof \WP_Post
                ? get_the_author_meta('display_name', (int) $post->post_author)
                : null,
            'date_published' => $post instanceof \WP_Post ? get_the_date('c', $post) : null,
            'date_modified' => $post instanceof \WP_Post ? get_the_modified_date('c', $post) : null,
            default => null,
        };
    }

    private function featured_image_url(int $post_id): ?string {
        if ($post_id <= 0) {
            return null;
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if (!$thumbnail_id) {
            return null;
        }

        $url = wp_get_attachment_url((int) $thumbnail_id);

        return is_string($url) && $url !== '' ? $url : null;
    }
}
