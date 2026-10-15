<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Db\Manual_Value_Repository;
use NobleSchema\Db\Snapshot_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\Generator\Generator;
use NobleSchema\Generator\Graph_Builder;
use NobleSchema\Mapping\Manual_Resolver;
use NobleSchema\Mapping\Resolver_Registry;
use NobleSchema\Mapping\Static_Resolver;
use NobleSchema\Targeting\Matcher;
use NobleSchema\Templates\Template_Loader;
use PHPUnit\Framework\TestCase;

class Generator_Test extends TestCase {
    public function test_is_field_missing(): void {
        $generator = $this->make_generator();
        $this->assertTrue($generator->is_field_missing(null, []));
        $this->assertTrue($generator->is_field_missing('', []));
        $this->assertTrue($generator->is_field_missing([], []));
        $this->assertFalse($generator->is_field_missing('ok', []));
        $this->assertFalse($generator->is_field_missing(['a' => 1], []));
    }

    public function test_generator_skips_config_when_required_missing(): void {
        $config = (object) [
            'id' => 1,
            'template_slug' => 'faqpage',
            'schema_type' => 'FAQPage',
            'placement' => 'global',
            'status' => 'active',
            'priority' => 10,
        ];

        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([$config]);

        $targets = $this->createMock(Target_Repository::class);
        $targets->method('for_config')->with(1)->willReturn([]);

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(1)->willReturn([
            (object) [
                'field_path' => 'mainEntity',
                'source_type' => 'static',
                'source_config' => wp_json_encode(['value' => null]),
                'required_override' => null,
            ],
        ]);

        $manuals = $this->createMock(Manual_Value_Repository::class);
        $manuals->method('for_post')->willReturn([]);

        $snapshots = $this->createMock(Snapshot_Repository::class);
        $snapshots->expects($this->once())->method('delete')->with(42);
        $snapshots->expects($this->never())->method('upsert');

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('faqpage')->willReturn([
            'slug' => 'faqpage',
            'label' => 'FAQ Page',
            'schema_type' => 'FAQPage',
            'node' => ['@type' => 'FAQPage'],
            'fields' => [
                [
                    'path' => 'mainEntity',
                    'label' => 'Questions',
                    'required' => true,
                    'value_type' => 'question_array',
                    'help' => '',
                ],
            ],
        ]);

        $registry = new Resolver_Registry();
        $registry->register('static', new Static_Resolver());

        $generator = new Generator(
            $configs,
            $targets,
            $fields,
            $manuals,
            $snapshots,
            $templates,
            new Matcher(),
            $registry,
            new Graph_Builder(),
        );

        $generator->generate_for_post(42);
    }

    public function test_generator_includes_config_when_non_strict_and_required_missing(): void {
        $config = (object) [
            'id' => 11,
            'template_slug' => 'article',
            'schema_type' => 'Article',
            'placement' => 'global',
            'status' => 'active',
            'priority' => 10,
            'strict_validation' => 0,
        ];

        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([$config]);

        $targets = $this->createMock(Target_Repository::class);
        $targets->method('for_config')->with(11)->willReturn([]);

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(11)->willReturn([
            (object) [
                'field_path' => 'headline',
                'source_type' => 'static',
                'source_config' => wp_json_encode(['value' => 'Partial Article']),
                'required_override' => null,
            ],
            (object) [
                'field_path' => 'description',
                'source_type' => 'static',
                'source_config' => wp_json_encode(['value' => null]),
                'required_override' => null,
            ],
        ]);

        $manuals = $this->createMock(Manual_Value_Repository::class);
        $manuals->method('for_post')->willReturn([]);

        $snapshots = $this->createMock(Snapshot_Repository::class);
        $snapshots->expects($this->never())->method('delete');
        $snapshots->expects($this->once())
            ->method('upsert')
            ->with(
                55,
                $this->callback(static function (string $json): bool {
                    $doc = json_decode($json, true);
                    return is_array($doc)
                        && ($doc['@graph'][0]['headline'] ?? '') === 'Partial Article'
                        && !array_key_exists('description', $doc['@graph'][0] ?? []);
                }),
                [11],
                true
            );

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('article')->willReturn([
            'slug' => 'article',
            'label' => 'Article',
            'schema_type' => 'Article',
            'node' => ['@type' => 'Article'],
            'fields' => [
                [
                    'path' => 'headline',
                    'label' => 'Headline',
                    'required' => true,
                    'value_type' => 'text',
                    'help' => '',
                ],
                [
                    'path' => 'description',
                    'label' => 'Description',
                    'required' => true,
                    'value_type' => 'text',
                    'help' => '',
                ],
            ],
        ]);

        $registry = new Resolver_Registry();
        $registry->register('static', new Static_Resolver());

        $generator = new Generator(
            $configs,
            $targets,
            $fields,
            $manuals,
            $snapshots,
            $templates,
            new Matcher(),
            $registry,
            new Graph_Builder(),
        );

        $generator->generate_for_post(55);
    }

    public function test_diagnose_reports_missing_required_fields(): void {
        $GLOBALS['noble_schema_test_posts'][42] = (object) [
            'ID' => 42,
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '',
            'post_title' => 'FAQ',
        ];

        $config = (object) [
            'id' => 1,
            'template_slug' => 'faqpage',
            'schema_type' => 'FAQPage',
            'placement' => 'global',
            'status' => 'active',
            'priority' => 10,
            'name' => 'FAQ Config',
        ];

        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([$config]);

        $targets = $this->createMock(Target_Repository::class);
        $targets->method('for_config')->with(1)->willReturn([]);

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(1)->willReturn([
            (object) [
                'field_path' => 'mainEntity',
                'source_type' => 'static',
                'source_config' => wp_json_encode(['value' => null]),
                'required_override' => null,
            ],
        ]);

        $manuals = $this->createMock(Manual_Value_Repository::class);
        $manuals->method('for_post')->willReturn([]);

        $snapshots = $this->createMock(Snapshot_Repository::class);

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('faqpage')->willReturn([
            'slug' => 'faqpage',
            'label' => 'FAQ Page',
            'schema_type' => 'FAQPage',
            'node' => ['@type' => 'FAQPage'],
            'fields' => [
                [
                    'path' => 'mainEntity',
                    'label' => 'Questions',
                    'required' => true,
                    'value_type' => 'question_array',
                    'help' => '',
                ],
            ],
        ]);

        $registry = new Resolver_Registry();
        $registry->register('static', new Static_Resolver());

        $generator = new Generator(
            $configs,
            $targets,
            $fields,
            $manuals,
            $snapshots,
            $templates,
            new Matcher(),
            $registry,
            new Graph_Builder(),
        );

        $report = $generator->diagnose_for_post(42);

        $this->assertFalse($report['would_generate']);
        $this->assertSame([], $report['included_config_ids']);
        $this->assertCount(1, $report['configs']);
        $this->assertSame('missing_required', $report['configs'][0]['outcome']);
        $this->assertSame('mainEntity', $report['configs'][0]['missing_required'][0]['path']);
        $this->assertSame('Questions', $report['configs'][0]['missing_required'][0]['label']);
    }

    public function test_generator_upserts_graph_when_config_resolves(): void {
        $config = (object) [
            'id' => 2,
            'template_slug' => 'article',
            'schema_type' => 'Article',
            'placement' => 'global',
            'status' => 'active',
            'priority' => 10,
        ];

        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([$config]);

        $targets = $this->createMock(Target_Repository::class);
        $targets->method('for_config')->with(2)->willReturn([]);

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(2)->willReturn([
            (object) [
                'field_path' => 'headline',
                'source_type' => 'static',
                'source_config' => wp_json_encode(['value' => 'Desert Guide']),
                'required_override' => null,
            ],
        ]);

        $manuals = $this->createMock(Manual_Value_Repository::class);
        $manuals->method('for_post')->willReturn([]);

        $snapshots = $this->createMock(Snapshot_Repository::class);
        $snapshots->expects($this->never())->method('delete');
        $snapshots->expects($this->once())
            ->method('upsert')
            ->with(
                7,
                $this->callback(static function (string $json): bool {
                    $doc = json_decode($json, true);
                    return is_array($doc)
                        && ($doc['@context'] ?? '') === 'https://schema.org'
                        && count($doc['@graph'] ?? []) === 1
                        && ($doc['@graph'][0]['headline'] ?? '') === 'Desert Guide';
                }),
                [2],
                true
            );

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('article')->willReturn([
            'slug' => 'article',
            'label' => 'Article',
            'schema_type' => 'Article',
            'node' => ['@type' => 'Article'],
            'fields' => [
                [
                    'path' => 'headline',
                    'label' => 'Headline',
                    'required' => true,
                    'value_type' => 'text',
                    'help' => '',
                ],
            ],
        ]);

        $registry = new Resolver_Registry();
        $registry->register('static', new Static_Resolver());

        $generator = new Generator(
            $configs,
            $targets,
            $fields,
            $manuals,
            $snapshots,
            $templates,
            new Matcher(),
            $registry,
            new Graph_Builder(),
        );

        $generator->generate_for_post(7);
    }

    public function test_generator_maps_question_array_to_schema_nodes(): void {
        $config = (object) [
            'id' => 3,
            'template_slug' => 'faqpage',
            'schema_type' => 'FAQPage',
            'placement' => 'global',
            'status' => 'active',
            'priority' => 10,
        ];

        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([$config]);

        $targets = $this->createMock(Target_Repository::class);
        $targets->method('for_config')->with(3)->willReturn([]);

        $faq_items = [
            ['question' => 'Where is Nevada?', 'answer' => 'In the western US.'],
        ];

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(3)->willReturn([
            (object) [
                'field_path' => 'mainEntity',
                'source_type' => 'static',
                'source_config' => wp_json_encode(['value' => $faq_items]),
                'required_override' => null,
            ],
        ]);

        $manuals = $this->createMock(Manual_Value_Repository::class);
        $manuals->method('for_post')->willReturn([]);

        $snapshots = $this->createMock(Snapshot_Repository::class);
        $snapshots->expects($this->once())
            ->method('upsert')
            ->with(
                99,
                $this->callback(static function (string $json): bool {
                    $doc = json_decode($json, true);
                    $entity = $doc['@graph'][0]['mainEntity'][0] ?? [];
                    return ($entity['@type'] ?? '') === 'Question'
                        && ($entity['name'] ?? '') === 'Where is Nevada?'
                        && ($entity['acceptedAnswer']['@type'] ?? '') === 'Answer'
                        && ($entity['acceptedAnswer']['text'] ?? '') === 'In the western US.';
                }),
                [3],
                true
            );

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('faqpage')->willReturn([
            'slug' => 'faqpage',
            'label' => 'FAQ Page',
            'schema_type' => 'FAQPage',
            'node' => ['@type' => 'FAQPage'],
            'fields' => [
                [
                    'path' => 'mainEntity',
                    'label' => 'Questions',
                    'required' => true,
                    'value_type' => 'question_array',
                    'help' => '',
                ],
            ],
        ]);

        $registry = new Resolver_Registry();
        $registry->register('static', new Static_Resolver());

        $generator = new Generator(
            $configs,
            $targets,
            $fields,
            $manuals,
            $snapshots,
            $templates,
            new Matcher(),
            $registry,
            new Graph_Builder(),
        );

        $generator->generate_for_post(99);
    }

    public function test_generator_decodes_static_json_string_question_array(): void {
        $config = (object) [
            'id' => 6,
            'template_slug' => 'faqpage',
            'schema_type' => 'FAQPage',
            'placement' => 'global',
            'status' => 'active',
            'priority' => 10,
        ];

        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([$config]);

        $targets = $this->createMock(Target_Repository::class);
        $targets->method('for_config')->with(6)->willReturn([]);

        $faq_json = wp_json_encode([
            ['question' => 'What is the capital?', 'answer' => 'Carson City.'],
        ]);

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(6)->willReturn([
            (object) [
                'field_path' => 'mainEntity',
                'source_type' => 'static',
                'source_config' => wp_json_encode(['value' => $faq_json]),
                'required_override' => null,
            ],
        ]);

        $manuals = $this->createMock(Manual_Value_Repository::class);
        $manuals->method('for_post')->willReturn([]);

        $snapshots = $this->createMock(Snapshot_Repository::class);
        $snapshots->expects($this->once())
            ->method('upsert')
            ->with(
                100,
                $this->callback(static function (string $json): bool {
                    $doc = json_decode($json, true);
                    $entity = $doc['@graph'][0]['mainEntity'][0] ?? [];

                    return ($entity['@type'] ?? '') === 'Question'
                        && ($entity['name'] ?? '') === 'What is the capital?'
                        && ($entity['acceptedAnswer']['text'] ?? '') === 'Carson City.';
                }),
                [6],
                true
            );

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('faqpage')->willReturn([
            'slug' => 'faqpage',
            'label' => 'FAQ Page',
            'schema_type' => 'FAQPage',
            'node' => ['@type' => 'FAQPage'],
            'fields' => [
                [
                    'path' => 'mainEntity',
                    'label' => 'Questions',
                    'required' => true,
                    'value_type' => 'question_array',
                    'help' => '',
                ],
            ],
        ]);

        $registry = new Resolver_Registry();
        $registry->register('static', new Static_Resolver());

        $generator = new Generator(
            $configs,
            $targets,
            $fields,
            $manuals,
            $snapshots,
            $templates,
            new Matcher(),
            $registry,
            new Graph_Builder(),
        );

        $generator->generate_for_post(100);
    }

    public function test_generator_decodes_manual_post_question_array(): void {
        $config = (object) [
            'id' => 4,
            'template_slug' => 'faqpage',
            'schema_type' => 'FAQPage',
            'placement' => 'global',
            'status' => 'active',
            'priority' => 10,
        ];

        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([$config]);

        $targets = $this->createMock(Target_Repository::class);
        $targets->method('for_config')->with(4)->willReturn([]);

        $faq_items = [
            ['question' => 'What is Reno known for?', 'answer' => 'Casinos and outdoor recreation.'],
        ];

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(4)->willReturn([
            (object) [
                'field_path' => 'mainEntity',
                'source_type' => 'manual_post',
                'source_config' => wp_json_encode(['field_path' => 'mainEntity']),
                'required_override' => null,
            ],
        ]);

        $manuals = $this->createMock(Manual_Value_Repository::class);
        $manuals->method('for_post')->with(55, 4)->willReturn([
            (object) [
                'field_path' => 'mainEntity',
                'block_client_id' => null,
                'value' => wp_json_encode($faq_items),
            ],
        ]);

        $snapshots = $this->createMock(Snapshot_Repository::class);
        $snapshots->expects($this->once())
            ->method('upsert')
            ->with(
                55,
                $this->callback(static function (string $json): bool {
                    $doc = json_decode($json, true);
                    $entity = $doc['@graph'][0]['mainEntity'][0] ?? [];

                    return ($entity['@type'] ?? '') === 'Question'
                        && ($entity['name'] ?? '') === 'What is Reno known for?'
                        && ($entity['acceptedAnswer']['@type'] ?? '') === 'Answer'
                        && ($entity['acceptedAnswer']['text'] ?? '') === 'Casinos and outdoor recreation.';
                }),
                [4],
                true
            );

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('faqpage')->willReturn([
            'slug' => 'faqpage',
            'label' => 'FAQ Page',
            'schema_type' => 'FAQPage',
            'node' => ['@type' => 'FAQPage'],
            'fields' => [
                [
                    'path' => 'mainEntity',
                    'label' => 'Questions',
                    'required' => true,
                    'value_type' => 'question_array',
                    'help' => '',
                ],
            ],
        ]);

        $registry = new Resolver_Registry();
        $registry->register('manual_post', new Manual_Resolver());

        $generator = new Generator(
            $configs,
            $targets,
            $fields,
            $manuals,
            $snapshots,
            $templates,
            new Matcher(),
            $registry,
            new Graph_Builder(),
        );

        $generator->generate_for_post(55);
    }

    public function test_generator_manual_block_resolves_from_matching_target_block(): void {
        $config = (object) [
            'id' => 5,
            'template_slug' => 'faqpage',
            'schema_type' => 'FAQPage',
            'placement' => 'global',
            'status' => 'active',
            'priority' => 10,
        ];

        $configs = $this->createMock(Config_Repository::class);
        $configs->method('all')->willReturn([$config]);

        $targets = $this->createMock(Target_Repository::class);
        $targets->method('for_config')->with(5)->willReturn([]);

        $faq_items = [
            ['question' => 'Where is Lake Tahoe?', 'answer' => 'On the Nevada-California border.'],
        ];

        $fields = $this->createMock(Field_Repository::class);
        $fields->method('for_config')->with(5)->willReturn([
            (object) [
                'field_path' => 'mainEntity',
                'source_type' => 'manual_block',
                'source_config' => wp_json_encode([
                    'block_name' => 'acf/faq',
                    'field_path' => 'mainEntity',
                ]),
                'required_override' => null,
            ],
        ]);

        $manuals = $this->createMock(Manual_Value_Repository::class);
        $manuals->method('for_post')->with(77, 5)->willReturn([]);

        $snapshots = $this->createMock(Snapshot_Repository::class);
        $snapshots->expects($this->once())
            ->method('upsert')
            ->with(
                77,
                $this->callback(static function (string $json): bool {
                    $doc = json_decode($json, true);
                    $entity = $doc['@graph'][0]['mainEntity'][0] ?? [];

                    return ($entity['@type'] ?? '') === 'Question'
                        && ($entity['name'] ?? '') === 'Where is Lake Tahoe?'
                        && ($entity['acceptedAnswer']['text'] ?? '') === 'On the Nevada-California border.';
                }),
                [5],
                true
            );

        $templates = $this->createMock(Template_Loader::class);
        $templates->method('get')->with('faqpage')->willReturn([
            'slug' => 'faqpage',
            'label' => 'FAQ Page',
            'schema_type' => 'FAQPage',
            'node' => ['@type' => 'FAQPage'],
            'fields' => [
                [
                    'path' => 'mainEntity',
                    'label' => 'Questions',
                    'required' => true,
                    'value_type' => 'question_array',
                    'help' => '',
                ],
            ],
        ]);

        $registry = new Resolver_Registry();
        $registry->register('manual_block', new Manual_Resolver());

        $generator = new Generator(
            $configs,
            $targets,
            $fields,
            $manuals,
            $snapshots,
            $templates,
            new Matcher(),
            $registry,
            new Graph_Builder(),
        );

        $GLOBALS['noble_schema_test_posts'][77] = (object) [
            'post_content' => '<!-- wp:acf/faq -->',
            'post_type' => 'page',
        ];
        $GLOBALS['noble_schema_test_parsed_blocks'] = [
            [
                'blockName' => 'acf/faq',
                'attrs' => [
                    'nobleSchemaManual' => [
                        '5|mainEntity' => $faq_items,
                    ],
                ],
                'innerBlocks' => [],
            ],
        ];

        try {
            $generator->generate_for_post(77);
        } finally {
            unset($GLOBALS['noble_schema_test_posts'][77], $GLOBALS['noble_schema_test_parsed_blocks']);
        }
    }

    private function make_generator(): Generator {
        return new Generator(
            $this->createMock(Config_Repository::class),
            $this->createMock(Target_Repository::class),
            $this->createMock(Field_Repository::class),
            $this->createMock(Manual_Value_Repository::class),
            $this->createMock(Snapshot_Repository::class),
            $this->createMock(Template_Loader::class),
            new Matcher(),
            new Resolver_Registry(),
            new Graph_Builder(),
        );
    }
}
