<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\SchemaOrg\Template_Factory;
use NobleSchema\SchemaOrg\Vocabulary;
use PHPUnit\Framework\TestCase;

class SchemaOrg_Storefront_Test extends TestCase {
    private string $uploads;

    protected function setUp(): void {
        $this->uploads = sys_get_temp_dir() . '/ns-uploads-' . uniqid('', true);
        mkdir($this->uploads, 0777, true);
        $GLOBALS['noble_schema_test_uploads'] = $this->uploads;
        unset($GLOBALS['noble_schema_test_uploads_error']);
    }

    protected function tearDown(): void {
        $custom = $this->uploads . '/noble-schema/templates';
        if (is_dir($custom)) {
            foreach (glob($custom . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($custom);
            @rmdir(dirname($custom));
        }
        if (is_dir($this->uploads)) {
            @rmdir($this->uploads);
        }
        unset($GLOBALS['noble_schema_test_uploads'], $GLOBALS['noble_schema_test_uploads_error']);
    }

    public function test_slugify_pascal_case(): void {
        $this->assertSame('tourist-destination', Template_Factory::slugify('TouristDestination'));
        $this->assertSame('faq-page', Template_Factory::slugify('FAQPage'));
        $this->assertSame('person', Template_Factory::slugify('Person'));
    }

    public function test_valid_slug(): void {
        $this->assertTrue(Template_Factory::is_valid_slug('tourist-destination'));
        $this->assertFalse(Template_Factory::is_valid_slug('TouristDestination'));
        $this->assertFalse(Template_Factory::is_valid_slug('bad_slug'));
        $this->assertFalse(Template_Factory::is_valid_slug('-leading'));
    }

    public function test_value_type_mapping(): void {
        $this->assertSame('image', Template_Factory::value_type_from_ranges('image', ['URL']));
        $this->assertSame('image', Template_Factory::value_type_from_ranges('caption', ['ImageObject']));
        $this->assertSame('datetime', Template_Factory::value_type_from_ranges('startDate', ['Date']));
        $this->assertSame('postal_address', Template_Factory::value_type_from_ranges('address', ['PostalAddress']));
        $this->assertSame('geo', Template_Factory::value_type_from_ranges('geo', ['GeoCoordinates']));
        $this->assertSame('person', Template_Factory::value_type_from_ranges('author', ['Person']));
        $this->assertSame('text', Template_Factory::value_type_from_ranges('name', ['Text']));
    }

    public function test_build_template_shape(): void {
        $factory = new Template_Factory(sys_get_temp_dir());
        $type = [
            'id'         => 'TouristDestination',
            'label'      => 'Tourist Destination',
            'comment'    => 'A tourist destination.',
            'properties' => [
                ['id' => 'name', 'label' => 'name', 'comment' => 'The name.', 'ranges' => ['Text']],
                ['id' => 'image', 'label' => 'image', 'comment' => 'An image.', 'ranges' => ['ImageObject']],
                ['id' => 'url', 'label' => 'url', 'comment' => 'URL', 'ranges' => ['URL']],
            ],
        ];

        $template = $factory->build($type, ['name', 'image'], '', '');
        $this->assertSame('tourist-destination', $template['slug']);
        $this->assertSame('TouristDestination', $template['schema_type']);
        $this->assertSame('https://schema.org/TouristDestination', $template['schema_url']);
        $this->assertSame(['@type' => 'TouristDestination'], $template['node']);
        $this->assertCount(2, $template['fields']);
        $this->assertTrue($template['fields'][0]['required']);
        $this->assertSame('image', $template['fields'][1]['value_type']);
    }

    public function test_documentation_url_uses_schema_type(): void {
        $template = [
            'schema_type' => 'Article',
            'schema_url'  => 'https://schema.org/Article',
        ];
        $this->assertSame(
            'https://schema.org/Article',
            Template_Factory::documentation_url($template, 'NewsArticle')
        );
        $this->assertSame(
            'https://schema.org/Article',
            Template_Factory::documentation_url($template, '')
        );
    }

    public function test_build_requires_properties(): void {
        $factory = new Template_Factory(sys_get_temp_dir());
        $this->expectException(\InvalidArgumentException::class);
        $factory->build(['id' => 'Thing', 'label' => 'Thing', 'properties' => []], []);
    }

    public function test_vocabulary_index_from_fixture_graph(): void {
        $graph = [
            [
                '@id' => 'schema:Thing',
                '@type' => 'rdfs:Class',
                'rdfs:label' => 'Thing',
                'rdfs:comment' => 'The most generic type.',
            ],
            [
                '@id' => 'schema:Person',
                '@type' => 'rdfs:Class',
                'rdfs:label' => 'Person',
                'rdfs:comment' => 'A person.',
                'rdfs:subClassOf' => ['@id' => 'schema:Thing'],
            ],
            [
                '@id' => 'schema:Text',
                '@type' => ['rdfs:Class', 'schema:DataType'],
                'rdfs:label' => 'Text',
            ],
            [
                '@id' => 'schema:name',
                '@type' => 'rdf:Property',
                'rdfs:label' => 'name',
                'rdfs:comment' => 'The name of the item.',
                'schema:domainIncludes' => ['@id' => 'schema:Thing'],
                'schema:rangeIncludes' => ['@id' => 'schema:Text'],
            ],
            [
                '@id' => 'schema:birthDate',
                '@type' => 'rdf:Property',
                'rdfs:label' => 'birthDate',
                'schema:domainIncludes' => ['@id' => 'schema:Person'],
                'schema:rangeIncludes' => ['@id' => 'schema:Date'],
            ],
        ];

        $index = Vocabulary::build_index_from_graph($graph);
        $this->assertArrayHasKey('Person', $index['types']);
        $this->assertArrayHasKey('Thing', $index['types']);
        $this->assertArrayNotHasKey('Text', $index['types']);
        $this->assertContains('name', $index['by_domain']['Thing']);
        $this->assertContains('birthDate', $index['by_domain']['Person']);
    }

    public function test_list_and_delete_custom_templates(): void {
        $GLOBALS['noble_schema_test_options'] = ['ns_logging_enabled' => '1'];
        $GLOBALS['noble_schema_test_logs'] = [];
        $GLOBALS['noble_schema_test_log_seq'] = 0;

        $factory = new Template_Factory(sys_get_temp_dir());
        $template = $factory->build(
            [
                'id'         => 'WebPage',
                'label'      => 'WebPage',
                'comment'    => '',
                'properties' => [
                    ['id' => 'name', 'label' => 'name', 'comment' => '', 'ranges' => ['Text']],
                ],
            ],
            ['name'],
            'web-page',
            'WebPage'
        );
        $written = $factory->write($template);
        $this->assertStringContainsString('/noble-schema/templates/web-page.json', $written['path']);
        $this->assertCount(1, $GLOBALS['noble_schema_test_logs']);
        $this->assertSame('template_installed', $GLOBALS['noble_schema_test_logs'][0]['category']);
        $this->assertStringContainsString('web-page', $GLOBALS['noble_schema_test_logs'][0]['message']);

        $listed = $factory->list_custom();
        $this->assertCount(1, $listed);
        $this->assertSame('web-page', $listed[0]['slug']);
        $this->assertSame('WebPage', $listed[0]['label']);

        $factory->delete_custom('web-page');
        $this->assertSame([], $factory->list_custom());
        $this->assertFalse(is_file($this->uploads . '/noble-schema/templates/web-page.json'));
        $this->assertCount(2, $GLOBALS['noble_schema_test_logs']);
        $this->assertStringContainsString('uninstalled', strtolower($GLOBALS['noble_schema_test_logs'][1]['message']));
    }

    public function test_custom_writable_detects_read_only_uploads(): void {
        chmod($this->uploads, 0555);
        $factory = new Template_Factory(sys_get_temp_dir());
        $this->assertFalse($factory->custom_writable());
        chmod($this->uploads, 0755);
    }

    public function test_custom_writable_when_directory_exists(): void {
        $custom = $this->uploads . '/noble-schema/templates';
        mkdir($custom, 0777, true);
        $factory = new Template_Factory(sys_get_temp_dir());
        $this->assertTrue($factory->custom_writable());
    }
}
