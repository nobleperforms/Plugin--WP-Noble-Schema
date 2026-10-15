<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Generator\Regen_Queue;
use NobleSchema\Targeting\Post_Meta_Rule;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class Post_Meta_Rule_Test extends TestCase {
    public function test_matches_values_core_operators(): void {
        $this->assertTrue(Post_Meta_Rule::matches_values(['1'], 'is', '1'));
        $this->assertTrue(Post_Meta_Rule::matches_values([], 'is_not', '1'));
        $this->assertTrue(Post_Meta_Rule::matches_values(['abc'], 'contains', 'b'));
        $this->assertTrue(Post_Meta_Rule::matches_values(['abc'], 'not_contains', 'z'));
        $this->assertTrue(Post_Meta_Rule::matches_values(['x'], 'exists', ''));
        $this->assertTrue(Post_Meta_Rule::matches_values([], 'not_exists', ''));
        $this->assertTrue(Post_Meta_Rule::matches_values([''], 'is_empty', ''));
        $this->assertTrue(Post_Meta_Rule::matches_values(['hi'], 'is_not_empty', ''));
        $this->assertTrue(Post_Meta_Rule::matches_values(['5'], 'gt', '4'));
        $this->assertFalse(Post_Meta_Rule::matches_values(['nope'], 'gt', '4'));
        $this->assertFalse(Post_Meta_Rule::matches_values(['5'], 'gt', 'x'));
    }

    public function test_build_filter_groups_sql_includes_post_meta_exists(): void {
        global $wpdb;
        $wpdb = new class {
            public string $postmeta = 'wp_postmeta';
            public string $term_relationships = 'wp_term_relationships';
            public string $term_taxonomy = 'wp_term_taxonomy';
            public string $terms = 'wp_terms';

            public function esc_like(string $text): string {
                return addcslashes($text, '_%\\');
            }
        };

        $queue = new Regen_Queue();
        $method = new ReflectionMethod(Regen_Queue::class, 'build_filter_groups_sql');
        $built = $method->invoke($queue, [[
            [
                'type'     => 'post_meta',
                'key'      => 'featured',
                'value'    => '1',
                'operator' => 'is',
            ],
        ]]);

        $this->assertStringContainsString('meta_key = %s', $built['sql']);
        $this->assertStringContainsString('meta_value = %s', $built['sql']);
        $this->assertSame(['featured', '1'], $built['params']);
    }
}
