<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Mapping\Acf_Field_Catalog;
use PHPUnit\Framework\TestCase;

class Acf_Field_Catalog_Test extends TestCase {
    public function test_flatten_skips_ui_only_types_and_builds_label(): void {
        $fields = [
            [
                'label' => 'Headline',
                'name'  => 'headline',
                'type'  => 'text',
            ],
            [
                'label' => 'Tab',
                'name'  => 'tab_1',
                'type'  => 'tab',
            ],
            [
                'label' => 'Details',
                'name'  => 'details',
                'type'  => 'group',
                'sub_fields' => [
                    [
                        'label' => 'Subtitle',
                        'name'  => 'subtitle',
                        'type'  => 'text',
                    ],
                ],
            ],
        ];

        $options = Acf_Field_Catalog::flatten_fields($fields);

        $this->assertSame([
            [
                'value' => 'headline',
                'label' => 'Headline (headline)',
            ],
            [
                'value' => 'details',
                'label' => 'Details (details)',
            ],
            [
                'value' => 'details_subtitle',
                'label' => 'Subtitle (details_subtitle)',
            ],
        ], $options);
    }

    public function test_filter_matches_label_or_name_case_insensitive(): void {
        $options = [
            ['value' => 'primary_destination', 'label' => 'Primary Destination (primary_destination)'],
            ['value' => 'headline', 'label' => 'Headline (headline)'],
            ['value' => 'details_subtitle', 'label' => 'Subtitle (details_subtitle)'],
        ];

        $this->assertSame(
            [
                ['value' => 'primary_destination', 'label' => 'Primary Destination (primary_destination)'],
            ],
            Acf_Field_Catalog::filter_options($options, 'DEST')
        );

        $this->assertSame(
            [
                ['value' => 'details_subtitle', 'label' => 'Subtitle (details_subtitle)'],
            ],
            Acf_Field_Catalog::filter_options($options, 'details_sub')
        );
    }

    public function test_filter_respects_limit(): void {
        $options = [];
        for ($i = 0; $i < 10; $i++) {
            $options[] = [
                'value' => 'field_' . $i,
                'label' => 'Field ' . $i . ' (field_' . $i . ')',
            ];
        }

        $this->assertCount(3, Acf_Field_Catalog::filter_options($options, 'field', 3));
    }
}
