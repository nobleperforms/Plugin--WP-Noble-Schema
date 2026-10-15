<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Post_Meta_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        $key = (string) ($source_config['key'] ?? '');
        $post_id = (int) ($context['post_id'] ?? 0);

        if ($key === '' || $post_id <= 0) {
            return null;
        }

        $value = get_post_meta($post_id, $key, true);

        return $value === '' || $value === false || $value === null ? null : $value;
    }
}
