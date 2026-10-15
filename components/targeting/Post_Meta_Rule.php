<?php

declare(strict_types=1);

namespace NobleSchema\Targeting;

/**
 * Shared post_meta additional-filter operators for Matcher + Regen_Queue.
 */
final class Post_Meta_Rule {
    public const OPERATORS = [
        'is',
        'is_not',
        'contains',
        'not_contains',
        'exists',
        'not_exists',
        'is_empty',
        'is_not_empty',
        'gt',
        'gte',
        'lt',
        'lte',
    ];

    private const NO_VALUE_OPS = [
        'exists',
        'not_exists',
        'is_empty',
        'is_not_empty',
    ];

    private const NUMERIC_OPS = [
        'gt',
        'gte',
        'lt',
        'lte',
    ];

    public static function sanitize_operator(string $operator): string {
        $operator = sanitize_key($operator);
        return in_array($operator, self::OPERATORS, true) ? $operator : 'is';
    }

    public static function requires_value(string $operator): bool {
        return !in_array(self::sanitize_operator($operator), self::NO_VALUE_OPS, true);
    }

    public static function sanitize_meta_key(string $key): string {
        $key = trim(sanitize_text_field($key));
        if ($key === '') {
            return '';
        }
        if (strlen($key) > 100) {
            $key = substr($key, 0, 100);
        }

        return $key;
    }

    public static function sanitize_value(string $value): string {
        $value = sanitize_text_field($value);
        if (strlen($value) > 255) {
            $value = substr($value, 0, 255);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{type:string,key:string,value:string,operator:string}|null
     */
    public static function normalize_rule(array $rule): ?array {
        $key = self::sanitize_meta_key((string) ($rule['key'] ?? ''));
        $operator = self::sanitize_operator((string) ($rule['operator'] ?? 'is'));
        $value = self::sanitize_value((string) ($rule['value'] ?? ''));

        if ($key === '') {
            return null;
        }
        if (self::requires_value($operator) && $value === '') {
            return null;
        }
        if (!self::requires_value($operator)) {
            $value = '';
        }

        return [
            'type'     => 'post_meta',
            'key'      => $key,
            'value'    => $value,
            'operator' => $operator,
        ];
    }

    /**
     * @param list<string|int|float|bool|null> $values Meta values for one key (empty = key missing).
     */
    public static function matches_values(array $values, string $operator, string $compare): bool {
        $operator = self::sanitize_operator($operator);
        $strings = [];
        foreach ($values as $value) {
            if (is_bool($value)) {
                $strings[] = $value ? '1' : '';
            } elseif ($value === null) {
                $strings[] = '';
            } else {
                $strings[] = (string) $value;
            }
        }

        return match ($operator) {
            'exists' => $strings !== [],
            'not_exists' => $strings === [],
            'is_empty' => in_array('', $strings, true),
            'is_not_empty' => self::has_non_empty($strings),
            'is' => in_array($compare, $strings, true),
            'is_not' => !in_array($compare, $strings, true),
            'contains' => self::any_contains($strings, $compare),
            'not_contains' => !self::any_contains($strings, $compare),
            'gt', 'gte', 'lt', 'lte' => self::any_numeric($strings, $operator, $compare),
            default => false,
        };
    }

    public static function matches_post(int $post_id, string $key, string $operator, string $compare): bool {
        $key = self::sanitize_meta_key($key);
        if ($key === '' || $post_id <= 0) {
            return false;
        }

        $raw = get_post_meta($post_id, $key, false);
        $values = is_array($raw) ? array_values($raw) : [];

        return self::matches_values($values, $operator, $compare);
    }

    /**
     * @param list<string> $strings
     */
    private static function has_non_empty(array $strings): bool {
        foreach ($strings as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $strings
     */
    private static function any_contains(array $strings, string $needle): bool {
        if ($needle === '') {
            return false;
        }
        foreach ($strings as $value) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $strings
     */
    private static function any_numeric(array $strings, string $operator, string $compare): bool {
        if (!is_numeric($compare)) {
            return false;
        }
        $compare_num = (float) $compare;
        foreach ($strings as $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $num = (float) $value;
            $ok = match ($operator) {
                'gt' => $num > $compare_num,
                'gte' => $num >= $compare_num,
                'lt' => $num < $compare_num,
                'lte' => $num <= $compare_num,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
