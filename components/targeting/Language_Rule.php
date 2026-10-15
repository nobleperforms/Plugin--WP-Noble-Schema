<?php

declare(strict_types=1);

namespace NobleSchema\Targeting;

use NobleSchema\Integrations\Plugin_Integrations;

/**
 * Shared WPML language additional-filter helpers for Matcher + Regen_Queue.
 */
final class Language_Rule {
    public const OPERATORS = ['is', 'is_not'];

    public static function sanitize_operator(string $operator): string {
        $operator = sanitize_key($operator);

        return in_array($operator, self::OPERATORS, true) ? $operator : 'is';
    }

    public static function sanitize_language(string $language): string {
        $language = strtolower(trim($language));
        $language = preg_replace('/[^a-z0-9_-]/', '', $language) ?? '';

        return $language;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{type:string,language:string,operator:string}|null
     */
    public static function normalize_rule(array $rule): ?array {
        $language = self::sanitize_language((string) ($rule['language'] ?? $rule['value'] ?? ''));
        if ($language === '') {
            return null;
        }

        return [
            'type'     => 'language',
            'language' => $language,
            'operator' => self::sanitize_operator((string) ($rule['operator'] ?? 'is')),
        ];
    }

    public static function matches_post(int $post_id, string $language, string $operator): bool {
        if (!Plugin_Integrations::is_wpml_active()) {
            return true;
        }

        $language = self::sanitize_language($language);
        $operator = self::sanitize_operator($operator);
        if ($language === '') {
            return true;
        }

        if ($post_id <= 0) {
            $code = Plugin_Integrations::wpml_current_language();
            if ($code === '') {
                return $operator === 'is_not';
            }
            $matched = $code === $language;

            return $operator === 'is_not' ? !$matched : $matched;
        }

        $code = Plugin_Integrations::post_language_code($post_id);
        if ($code === '') {
            return $operator === 'is_not';
        }

        $matched = $code === $language;

        return $operator === 'is_not' ? !$matched : $matched;
    }
}
