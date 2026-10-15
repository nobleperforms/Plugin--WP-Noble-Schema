<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Manual_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        $field_path = (string) ($source_config['field_path'] ?? '');
        $config_id = (string) ($context['config_id'] ?? '');

        if ($field_path === '' || $config_id === '') {
            return null;
        }

        $block_name = (string) ($source_config['block_name'] ?? '');
        if ($block_name !== '') {
            $blocks = $context['blocks'] ?? [];

            return is_array($blocks)
                ? $this->resolve_from_blocks($blocks, $block_name, "{$config_id}|{$field_path}")
                : null;
        }

        $manual_values = $context['manual_values'] ?? [];
        if (!is_array($manual_values)) {
            return null;
        }

        $key = "{$config_id}|{$field_path}|";

        return $manual_values[$key] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function resolve_from_blocks(array $blocks, string $block_name, string $value_key): mixed {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['blockName'] ?? '') === $block_name) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $values = is_array($attrs['nobleSchemaManual'] ?? null)
                    ? $attrs['nobleSchemaManual']
                    : [];

                if (array_key_exists($value_key, $values) && !$this->is_empty($values[$value_key])) {
                    return $values[$value_key];
                }
            }

            $inner_blocks = $block['innerBlocks'] ?? [];
            if (is_array($inner_blocks) && $inner_blocks !== []) {
                $value = $this->resolve_from_blocks($inner_blocks, $block_name, $value_key);
                if (!$this->is_empty($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function is_empty(mixed $value): bool {
        if ($value === null || $value === false) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return is_array($value) && $value === [];
    }
}
