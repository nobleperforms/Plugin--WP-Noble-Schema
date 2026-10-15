<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Block_Content_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        $block_name = (string) ($source_config['block_name'] ?? '');
        $mode = (string) ($source_config['mode'] ?? '');
        $blocks = $context['blocks'] ?? [];

        if ($block_name === '' || $mode === '' || !is_array($blocks)) {
            return null;
        }

        $block = $this->find_block($blocks, $block_name);
        if ($block === null) {
            return null;
        }

        return match ($mode) {
            'faq_from_inner_blocks' => $this->extract_faq_from_inner_blocks($block),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $block
     * @return array<int, array{question: string, answer: string}>|null
     */
    private function extract_faq_from_inner_blocks(array $block): ?array {
        $inner_blocks = $block['innerBlocks'] ?? [];
        if (!is_array($inner_blocks) || $inner_blocks === []) {
            return null;
        }

        $items = [];

        foreach ($inner_blocks as $inner) {
            $attrs = $inner['attrs'] ?? [];
            if (!is_array($attrs)) {
                continue;
            }

            $question = $this->string_value($attrs['question'] ?? $attrs['title'] ?? $attrs['heading'] ?? null);
            $answer = $this->string_value($attrs['answer'] ?? $attrs['content'] ?? $attrs['text'] ?? null);

            if ($question === null && $answer === null && !empty($inner['innerBlocks']) && is_array($inner['innerBlocks'])) {
                $nested = $this->extract_faq_from_inner_blocks($inner);
                if ($nested !== null) {
                    $items = array_merge($items, $nested);
                }
                continue;
            }

            if ($question !== null && $answer !== null) {
                $items[] = [
                    'question' => $question,
                    'answer' => $answer,
                ];
            }
        }

        return $items === [] ? null : $items;
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

    private function string_value(mixed $value): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
