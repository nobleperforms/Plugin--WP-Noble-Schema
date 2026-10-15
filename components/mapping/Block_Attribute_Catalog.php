<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Block_Attribute_Catalog {
    /**
     * @param mixed $attributes Block type attributes schema (name => schema).
     * @return list<string>
     */
    public static function attribute_names($attributes): array {
        if (!is_array($attributes) || $attributes === []) {
            return [];
        }

        $names = [];
        foreach (array_keys($attributes) as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }

        sort($names, SORT_STRING);

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function all_registered(): array {
        if (!class_exists('WP_Block_Type_Registry')) {
            return [];
        }

        $map = [];
        foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $block) {
            $name = trim((string) ($block->name ?? ''));
            if ($name === '') {
                continue;
            }
            $map[$name] = self::attribute_names($block->attributes ?? []);
        }

        ksort($map, SORT_STRING);

        return $map;
    }
}
