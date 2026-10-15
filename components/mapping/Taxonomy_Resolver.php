<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Taxonomy_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        $taxonomy = (string) ($source_config['taxonomy'] ?? '');
        $return = (string) ($source_config['return'] ?? 'names');
        $post_id = (int) ($context['post_id'] ?? 0);

        if ($taxonomy === '' || $post_id <= 0) {
            return null;
        }

        $terms = wp_get_post_terms($post_id, $taxonomy);
        if (is_wp_error($terms) || $terms === []) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn(\WP_Term $term) => match ($return) {
                'slugs' => $term->slug,
                'urls' => get_term_link($term),
                default => $term->name,
            },
            $terms
        ), static fn(mixed $value) => $value !== null && $value !== '' && !is_wp_error($value)));
    }
}
