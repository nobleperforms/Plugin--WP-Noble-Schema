<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Coverage\Coverage_Classifier;
use PHPUnit\Framework\TestCase;

class Coverage_Classifier_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
    }

    private function graph(array $types): string {
        $nodes = [];
        foreach ($types as $i => $type) {
            $nodes[] = [
                '@type' => $type,
                '@id'   => 'https://example.com/#' . $i,
            ];
        }

        return (string) wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => $nodes,
        ]);
    }

    public function test_empty_is_none(): void {
        $result = Coverage_Classifier::classify([
            'graph_json'  => null,
            'is_complete' => false,
            'config_ids'  => [],
        ]);
        $this->assertSame('none', $result['tier']);
        $this->assertFalse($result['has_page_specific']);
        $this->assertFalse($result['invalid']);
    }

    public function test_organization_only_from_sitewide_is_sitewide_only(): void {
        $result = Coverage_Classifier::classify([
            'graph_json'         => $this->graph(['Organization']),
            'is_complete'        => true,
            'config_ids'         => [9],
            'config_placements'  => [9 => 'sitewide'],
        ]);
        $this->assertSame('sitewide_only', $result['tier']);
        $this->assertTrue($result['has_sitewide']);
        $this->assertFalse($result['has_page_specific']);
    }

    public function test_organization_only_from_global_is_sitewide_only(): void {
        $result = Coverage_Classifier::classify([
            'graph_json'         => $this->graph(['Organization']),
            'is_complete'        => true,
            'config_ids'         => [1],
            'config_placements'  => [1 => 'global'],
        ]);
        $this->assertSame('sitewide_only', $result['tier']);
        $this->assertTrue($result['has_sitewide']);
        $this->assertFalse($result['has_page_specific']);
    }

    public function test_article_plus_organization_is_page_tier(): void {
        $result = Coverage_Classifier::classify([
            'graph_json'        => $this->graph(['Organization', 'Article']),
            'is_complete'       => true,
            'config_ids'        => [1, 2],
            'config_placements' => [1 => 'global', 2 => 'targeted'],
        ]);
        $this->assertSame('validated', $result['tier']);
        $this->assertTrue($result['has_page_specific']);
        $this->assertTrue($result['has_sitewide']);
        $this->assertContains('Article', $result['page_types']);
    }

    public function test_incomplete_page_schema(): void {
        $result = Coverage_Classifier::classify([
            'graph_json'        => $this->graph(['Article']),
            'is_complete'       => false,
            'config_ids'        => [2],
            'config_placements' => [2 => 'targeted'],
        ]);
        $this->assertSame('page_incomplete', $result['tier']);
    }

    public function test_invalid_complete_is_page_complete_not_validated(): void {
        $json = (string) wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                ['@type' => 'Article', '@id' => 'https://example.com/#a'],
                ['name' => 'bad'], // missing @type → error
            ],
        ]);
        $result = Coverage_Classifier::classify([
            'graph_json'        => $json,
            'is_complete'       => true,
            'config_ids'        => [2],
            'config_placements' => [2 => 'targeted'],
        ]);
        $this->assertTrue($result['invalid']);
        $this->assertSame('page_complete', $result['tier']);
    }

    public function test_page_targeted_webpage_only_is_page_specific(): void {
        $result = Coverage_Classifier::classify([
            'graph_json'        => $this->graph(['WebPage']),
            'is_complete'       => true,
            'config_ids'        => [9],
            'config_placements' => [9 => 'targeted'],
        ]);
        $this->assertTrue($result['has_page_specific']);
        $this->assertSame('validated', $result['tier']);
    }

    public function test_bare_webpage_without_page_config_is_sitewide_only(): void {
        $result = Coverage_Classifier::classify([
            'graph_json'        => $this->graph(['WebPage']),
            'is_complete'       => true,
            'config_ids'        => [1],
            'config_placements' => [1 => 'global'],
        ]);
        $this->assertSame('sitewide_only', $result['tier']);
    }

    public function test_custom_sitewide_types_from_settings(): void {
        $GLOBALS['noble_schema_test_options'][\NobleSchema\Admin\Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES] = 'Organization,BreadcrumbList';

        $result = Coverage_Classifier::classify([
            'graph_json'        => $this->graph(['BreadcrumbList']),
            'is_complete'       => true,
            'config_ids'        => [1],
            'config_placements' => [1 => 'global'],
        ]);
        $this->assertSame('sitewide_only', $result['tier']);
        $this->assertContains('BreadcrumbList', $result['sitewide_types']);
        $this->assertSame([], $result['page_types']);
    }

    public function test_default_types_stay_sitewide_when_option_omits_them(): void {
        $GLOBALS['noble_schema_test_options'][\NobleSchema\Admin\Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES] = 'BreadcrumbList';

        $result = Coverage_Classifier::classify([
            'graph_json'        => $this->graph(['WebPage']),
            'is_complete'       => true,
            'config_ids'        => [1],
            'config_placements' => [1 => 'global'],
        ]);
        $this->assertSame('sitewide_only', $result['tier']);
        $this->assertContains('WebPage', $result['sitewide_types']);
    }
}
