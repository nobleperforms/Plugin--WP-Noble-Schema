<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Generator\Regen_Queue;
use PHPUnit\Framework\TestCase;

class Regen_Queue_Filter_Test extends TestCase {
    public function test_normalize_filter_groups_sanitizes_and_drops_invalid(): void {
        $normalized = Regen_Queue::normalize_filter_groups([
            [
                [
                    'type'     => 'taxonomy',
                    'taxonomy' => 'category',
                    'term_id'  => 42,
                    'operator' => 'is',
                ],
                [
                    'type'  => 'has_block',
                    'block' => '  Noble/FAQ  ',
                ],
                [
                    'type'     => 'post_meta',
                    'key'      => ' featured ',
                    'value'    => '1',
                    'operator' => 'is',
                ],
                [
                    'type'     => 'post_meta',
                    'key'      => 'rating',
                    'value'    => '',
                    'operator' => 'exists',
                ],
                [
                    'type'     => 'post_meta',
                    'key'      => '',
                    'value'    => 'x',
                    'operator' => 'is',
                ],
            ],
            [
                [
                    'type'     => 'taxonomy',
                    'taxonomy' => '',
                    'term_id'  => 99,
                    'operator' => 'is',
                ],
            ],
            'not-a-group',
        ]);

        $this->assertSame([
            [
                [
                    'type'     => 'taxonomy',
                    'taxonomy' => 'category',
                    'term_id'  => 42,
                    'operator' => 'is',
                ],
                [
                    'type'  => 'has_block',
                    'block' => 'noble/faq',
                ],
                [
                    'type'     => 'post_meta',
                    'key'      => 'featured',
                    'value'    => '1',
                    'operator' => 'is',
                ],
                [
                    'type'     => 'post_meta',
                    'key'      => 'rating',
                    'value'    => '',
                    'operator' => 'exists',
                ],
            ],
        ], $normalized);
    }

    public function test_normalize_filter_groups_includes_language_rules(): void {
        $normalized = Regen_Queue::normalize_filter_groups([
            [
                [
                    'type'     => 'language',
                    'language' => 'EN',
                    'operator' => 'is',
                ],
                [
                    'type'     => 'language',
                    'language' => 'es',
                    'operator' => 'is_not',
                ],
                [
                    'type'     => 'language',
                    'language' => '',
                    'operator' => 'is',
                ],
            ],
        ]);

        $this->assertSame([
            [
                [
                    'type'     => 'language',
                    'language' => 'en',
                    'operator' => 'is',
                ],
                [
                    'type'     => 'language',
                    'language' => 'es',
                    'operator' => 'is_not',
                ],
            ],
        ], $normalized);
    }

    public function test_filter_groups_from_target_rows_groups_by_rule_group(): void {
        $rows = [
            (object) [
                'rule_type'  => 'post_type',
                'rule_value' => 'event',
                'rule_key'   => null,
                'rule_group' => 0,
            ],
            (object) [
                'rule_type'  => 'taxonomy',
                'rule_key'   => 'category',
                'rule_value' => '42',
                'operator'   => 'is',
                'rule_group' => 0,
            ],
            (object) [
                'rule_type'  => 'has_block',
                'rule_key'   => 'filter',
                'rule_value' => 'core/paragraph',
                'rule_group' => 0,
            ],
            (object) [
                'rule_type'  => 'taxonomy',
                'rule_key'   => 'region',
                'rule_value' => '7',
                'operator'   => 'is_not',
                'rule_group' => 1,
            ],
            (object) [
                'rule_type'  => 'has_block',
                'rule_key'   => null,
                'rule_value' => 'noble/schema',
                'rule_group' => 0,
            ],
            (object) [
                'rule_type'  => 'post_meta',
                'rule_key'   => 'featured',
                'rule_value' => '1',
                'operator'   => 'is',
                'rule_group' => 0,
            ],
        ];

        $groups = Regen_Queue::filter_groups_from_target_rows($rows);

        $this->assertSame([
            [
                [
                    'type'     => 'taxonomy',
                    'taxonomy' => 'category',
                    'term_id'  => 42,
                    'operator' => 'is',
                ],
                [
                    'type'  => 'has_block',
                    'block' => 'core/paragraph',
                ],
                [
                    'type'  => 'has_block',
                    'block' => 'noble/schema',
                ],
                [
                    'type'     => 'post_meta',
                    'key'      => 'featured',
                    'value'    => '1',
                    'operator' => 'is',
                ],
            ],
            [
                [
                    'type'     => 'taxonomy',
                    'taxonomy' => 'region',
                    'term_id'  => 7,
                    'operator' => 'is_not',
                ],
            ],
        ], $groups);
    }

    public function test_merge_filter_groups_returns_empty_when_either_side_empty(): void {
        $filters = [
            [
                [
                    'type'     => 'taxonomy',
                    'taxonomy' => 'category',
                    'term_id'  => 15,
                    'operator' => 'is',
                ],
            ],
        ];

        $this->assertSame([], Regen_Queue::merge_filter_groups([], $filters));
        $this->assertSame([], Regen_Queue::merge_filter_groups($filters, []));
    }

    public function test_merge_filter_groups_concatenates_groups(): void {
        $a = [
            [
                [
                    'type'     => 'taxonomy',
                    'taxonomy' => 'category',
                    'term_id'  => 15,
                    'operator' => 'is',
                ],
            ],
        ];
        $b = [
            [
                [
                    'type'  => 'has_block',
                    'block' => 'noble/faq',
                ],
            ],
        ];

        $this->assertSame([
            [
                [
                    'type'     => 'taxonomy',
                    'taxonomy' => 'category',
                    'term_id'  => 15,
                    'operator' => 'is',
                ],
            ],
            [
                [
                    'type'  => 'has_block',
                    'block' => 'noble/faq',
                ],
            ],
        ], Regen_Queue::merge_filter_groups($a, $b));
    }

    public function test_resolve_tools_queue_scope_keeps_filters_for_global(): void {
        $GLOBALS['noble_schema_test_post_types'] = ['post' => 'post', 'page' => 'page'];

        $queue = new Regen_Queue();
        $method = new \ReflectionMethod(Regen_Queue::class, 'resolve_tools_queue_scope');

        $scope = $method->invoke($queue, [
            'placement'      => 'global',
            'post_types'     => ['post'],
            'page_templates' => ['templates/landing.php'],
            'blocks'         => ['core/paragraph'],
            'filter_groups'  => [
                [
                    [
                        'type'  => 'has_block',
                        'block' => 'acf/landing-page-dyn-tik',
                    ],
                    [
                        'type'     => 'post_meta',
                        'key'      => 'featured',
                        'value'    => '1',
                        'operator' => 'is',
                    ],
                ],
            ],
            'posts'    => [],
            'archives' => [],
        ]);

        $this->assertSame(['post', 'page'], $scope['post_types']);
        $this->assertSame([], $scope['page_templates']);
        $this->assertSame([], $scope['blocks']);
        $this->assertSame([
            [
                [
                    'type'  => 'has_block',
                    'block' => 'acf/landing-page-dyn-tik',
                ],
                [
                    'type'     => 'post_meta',
                    'key'      => 'featured',
                    'value'    => '1',
                    'operator' => 'is',
                ],
            ],
        ], $scope['filters']);
    }

    public function test_resolve_config_scope_ignores_placement_blocks(): void {
        $queue = new Regen_Queue();
        $method = new \ReflectionMethod(Regen_Queue::class, 'resolve_config_scope');
        $scope = $method->invoke($queue, [
            (object) [
                'rule_type'  => 'post_type',
                'rule_value' => 'post',
                'rule_key'   => null,
                'rule_group' => 0,
            ],
            (object) [
                'rule_type'  => 'has_block',
                'rule_value' => 'acf/faq',
                'rule_key'   => null,
                'rule_group' => 0,
            ],
        ]);

        $this->assertSame(['post'], $scope['post_types']);
        $this->assertSame([], $scope['blocks']);
        $this->assertSame([
            [
                [
                    'type'  => 'has_block',
                    'block' => 'acf/faq',
                ],
            ],
        ], $scope['filters']);
    }
}
