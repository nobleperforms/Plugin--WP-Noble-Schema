<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Mapping\Manual_Resolver;
use PHPUnit\Framework\TestCase;

class Manual_Resolver_Test extends TestCase {
    public function test_manual_block_returns_first_non_empty_matching_block_value(): void {
        $resolver = new Manual_Resolver();
        $context = [
            'config_id' => '12',
            'blocks' => [
                [
                    'blockName' => 'acf/faq',
                    'attrs' => [
                        'nobleSchemaManual' => ['12|description' => ''],
                    ],
                    'innerBlocks' => [],
                ],
                [
                    'blockName' => 'core/group',
                    'attrs' => [],
                    'innerBlocks' => [
                        [
                            'blockName' => 'acf/faq',
                            'attrs' => [
                                'nobleSchemaManual' => ['12|description' => 'Nested value'],
                            ],
                            'innerBlocks' => [],
                        ],
                    ],
                ],
                [
                    'blockName' => 'acf/faq',
                    'attrs' => [
                        'nobleSchemaManual' => ['12|description' => 'Later value'],
                    ],
                    'innerBlocks' => [],
                ],
            ],
        ];

        $this->assertSame(
            'Nested value',
            $resolver->resolve(
                ['block_name' => 'acf/faq', 'field_path' => 'description'],
                $context
            )
        );
    }

    public function test_manual_block_returns_null_when_matching_values_are_empty(): void {
        $resolver = new Manual_Resolver();

        $this->assertNull($resolver->resolve(
            ['block_name' => 'acf/faq', 'field_path' => 'description'],
            [
                'config_id' => '12',
                'blocks' => [
                    [
                        'blockName' => 'acf/faq',
                        'attrs' => [
                            'nobleSchemaManual' => ['12|description' => []],
                        ],
                        'innerBlocks' => [],
                    ],
                ],
            ]
        ));
    }

    public function test_manual_post_still_reads_post_scoped_repository_value(): void {
        $resolver = new Manual_Resolver();

        $this->assertSame(
            'Post value',
            $resolver->resolve(
                ['field_path' => 'description'],
                [
                    'config_id' => '12',
                    'manual_values' => [
                        '12|description|' => 'Post value',
                    ],
                ]
            )
        );
    }
}
