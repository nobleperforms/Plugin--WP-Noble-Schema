<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Acf_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        if (!function_exists('get_field')) {
            return null;
        }

        $name = (string) ($source_config['name'] ?? '');
        $post_id = (int) ($context['post_id'] ?? 0);

        if ($name === '' || $post_id <= 0) {
            return null;
        }

        $value = get_field($name, $post_id);

        return $value === '' || $value === false || $value === null ? null : $value;
    }
}
