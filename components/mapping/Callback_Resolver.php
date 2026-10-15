<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Callback_Resolver implements Resolver_Interface {
    public const HOOK_PREFIX = 'ns_schema_callback_';

    public static function hook_for_slug(string $slug): string {
        return self::HOOK_PREFIX . $slug;
    }

    /**
     * @return string[]
     */
    public function slugs(): array {
        global $wp_filter;

        if (!is_array($wp_filter) && !is_object($wp_filter)) {
            return [];
        }

        $slugs = [];
        foreach (array_keys((array) $wp_filter) as $tag) {
            if (!is_string($tag) || !str_starts_with($tag, self::HOOK_PREFIX)) {
                continue;
            }

            $slug = substr($tag, strlen(self::HOOK_PREFIX));
            if ($slug === '' || !has_filter($tag)) {
                continue;
            }

            $slugs[] = $slug;
        }

        sort($slugs);

        return $slugs;
    }

    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        $slug = (string) ($source_config['slug'] ?? '');
        if ($slug === '') {
            return null;
        }

        $hook = self::hook_for_slug($slug);
        if (!has_filter($hook)) {
            return null;
        }

        return apply_filters($hook, $source_config, $context);
    }
}
