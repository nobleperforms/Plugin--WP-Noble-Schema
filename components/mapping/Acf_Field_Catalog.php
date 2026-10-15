<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

use NobleSchema\Integrations\Plugin_Integrations;

class Acf_Field_Catalog {
    /** @var list<string> */
    private const SKIP_TYPES = ['tab', 'message', 'accordion', 'clone'];

    /**
     * @param list<array<string, mixed>> $fields
     * @return list<array{value: string, label: string}>
     */
    public static function flatten_fields(array $fields, string $prefix = ''): array {
        $out = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = (string) ($field['type'] ?? '');
            if ($type !== '' && in_array($type, self::SKIP_TYPES, true)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $full_name = $prefix !== '' ? $prefix . '_' . $name : $name;
            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                $label = $full_name;
            }

            $out[] = [
                'value' => $full_name,
                'label' => sprintf('%s (%s)', $label, $full_name),
            ];

            $sub_fields = $field['sub_fields'] ?? null;
            if (is_array($sub_fields) && $sub_fields !== []) {
                foreach (self::flatten_fields($sub_fields, $full_name) as $child) {
                    $out[] = $child;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array{value: string, label: string}> $options
     * @return list<array{value: string, label: string}>
     */
    public static function filter_options(array $options, string $search, int $limit = 50): array {
        $search = trim($search);
        $limit = max(1, $limit);
        $needle = $search !== '' ? strtolower($search) : '';
        $out = [];

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = (string) ($option['value'] ?? '');
            $label = (string) ($option['label'] ?? $value);
            if ($value === '') {
                continue;
            }
            if ($needle !== '') {
                $haystack = strtolower($label . ' ' . $value);
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }
            $out[] = [
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function all_options(): array {
        if (!Plugin_Integrations::is_acf_active() || !function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return [];
        }

        $groups = acf_get_field_groups();
        if (!is_array($groups) || $groups === []) {
            return [];
        }

        $options = [];
        $seen = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $fields = acf_get_fields($group);
            if (!is_array($fields) || $fields === []) {
                continue;
            }
            foreach (self::flatten_fields($fields) as $option) {
                $value = $option['value'];
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $options[] = $option;
            }
        }

        usort(
            $options,
            static fn(array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label'])
        );

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function search(string $search, int $limit = 50): array {
        return self::filter_options(self::all_options(), $search, $limit);
    }
}
