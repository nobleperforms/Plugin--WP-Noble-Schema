<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Block_Attr_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        $block_name = (string) ($source_config['block_name'] ?? '');
        $attr = (string) ($source_config['attr'] ?? '');
        $blocks = $context['blocks'] ?? [];

        if ($block_name === '' || $attr === '' || !is_array($blocks)) {
            return null;
        }

        $block = $this->find_block($blocks, $block_name);
        if ($block === null) {
            return null;
        }

        $attrs = $block['attrs'] ?? [];

        return is_array($attrs) && array_key_exists($attr, $attrs) ? $attrs[$attr] : null;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function find_block(array $blocks, string $block_name): ?array {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === $block_name) {
                return $block;
            }

            $inner_blocks = $block['innerBlocks'] ?? [];
            if (is_array($inner_blocks) && $inner_blocks !== []) {
                $match = $this->find_block($inner_blocks, $block_name);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }
}
