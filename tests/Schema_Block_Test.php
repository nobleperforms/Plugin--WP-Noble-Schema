<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Blocks\Schema_Block;
use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Templates\Template_Loader;
use PHPUnit\Framework\TestCase;

class Schema_Block_Test extends TestCase {
    public function test_editor_blocks_groups_manual_fields_by_target_block(): void {
        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([
            (object) [
                'id' => 12,
                'name' => 'FAQ schema',
                'schema_type' => 'FAQPage',
                'template_slug' => 'faqpage',
                'status' => 'active',
            ],
        ]);

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(12)->willReturn([
            (object) [
                'field_path' => 'description',
                'source_type' => 'manual_block',
                'source_config' => wp_json_encode([
                    'block_name' => 'acf/faq',
                    'field_path' => 'description',
                ]),
                'required_override' => null,
            ],
            (object) [
                'field_path' => 'headline',
                'source_type' => 'core',
                'source_config' => '{}',
                'required_override' => null,
            ],
        ]);

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('faqpage')->willReturn([
            'fields' => [
                [
                    'path' => 'description',
                    'label' => 'Description',
                    'help' => 'Describe this FAQ.',
                    'required' => true,
                    'value_type' => 'text',
                ],
            ],
        ]);

        $block = new Schema_Block($configs, $fields, $templates);

        $this->assertSame([
            'acf/faq' => [
                [
                    'config_id' => 12,
                    'config_label' => 'FAQ schema',
                    'key' => '12|description',
                    'path' => 'description',
                    'label' => 'Description',
                    'help' => 'Describe this FAQ.',
                    'required' => true,
                    'value_type' => 'text',
                ],
            ],
        ], $block->editor_blocks());
    }

    public function test_register_manual_attributes_preserves_values_for_all_blocks(): void {
        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([
            (object) [
                'id' => 12,
                'name' => 'FAQ schema',
                'schema_type' => 'FAQPage',
                'template_slug' => 'faqpage',
                'status' => 'active',
            ],
        ]);

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(12)->willReturn([
            (object) [
                'field_path' => 'description',
                'source_type' => 'manual_block',
                'source_config' => wp_json_encode([
                    'block_name' => 'acf/faq',
                    'field_path' => 'description',
                ]),
                'required_override' => null,
            ],
        ]);

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->willReturn(['fields' => []]);

        $block = new Schema_Block($configs, $fields, $templates);
        $args = ['attributes' => ['existing' => ['type' => 'string']]];

        $mapped = $block->register_manual_attributes($args, 'acf/faq');
        $unmapped = $block->register_manual_attributes($args, 'core/paragraph');

        $this->assertSame('object', $mapped['attributes']['nobleSchemaManual']['type']);
        $this->assertEquals((object) [], $mapped['attributes']['nobleSchemaManual']['default']);
        $this->assertSame('object', $unmapped['attributes']['nobleSchemaManual']['type']);
    }
}
