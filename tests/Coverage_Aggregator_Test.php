<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Coverage\Coverage_Aggregator;
use NobleSchema\Coverage\Coverage_Classifier;
use PHPUnit\Framework\TestCase;

class Coverage_Aggregator_Test extends TestCase {
    private function graph(array $types): string {
        $nodes = [];
        foreach ($types as $i => $type) {
            $nodes[] = ['@type' => $type, '@id' => 'https://example.com/#' . $i];
        }

        return (string) wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => $nodes,
        ]);
    }

    public function test_coverage_percent_excludes_sitewide_only(): void {
        $agg = new Coverage_Aggregator();
        $summary = $agg->build_summary([
            [
                'post_id' => 1,
                'title' => 'A',
                'post_type' => 'post',
                'graph_json' => $this->graph(['Organization']),
                'is_complete' => true,
                'config_ids' => [1],
                'config_placements' => [1 => 'global'],
            ],
            [
                'post_id' => 2,
                'title' => 'B',
                'post_type' => 'post',
                'graph_json' => $this->graph(['Article']),
                'is_complete' => true,
                'config_ids' => [2],
                'config_placements' => [2 => 'targeted'],
            ],
            [
                'post_id' => 3,
                'title' => 'C',
                'post_type' => 'page',
                'graph_json' => null,
                'is_complete' => false,
                'config_ids' => [],
            ],
        ], [
            [
                'archive_key' => 'home',
                'label' => 'Blog',
                'graph_json' => $this->graph(['WebSite']),
                'is_complete' => true,
                'config_ids' => [1],
                'config_placements' => [1 => 'global'],
            ],
        ]);

        $this->assertSame(3, $summary['published_total']);
        $this->assertSame(1, $summary['page_schema_posts']);
        $this->assertSame(33.3, $summary['coverage_percent']);
        $this->assertSame(1, $summary['tier_counts'][Coverage_Classifier::TIER_SITEWIDE_ONLY]);
        $this->assertSame(1, $summary['tier_counts'][Coverage_Classifier::TIER_VALIDATED]);
        $this->assertSame(1, $summary['tier_counts'][Coverage_Classifier::TIER_NONE]);
        $this->assertCount(1, $summary['archives']);
        $this->assertSame(Coverage_Classifier::TIER_SITEWIDE_ONLY, $summary['archives'][0]['tier']);
        $this->assertSame(1, $summary['sitewide_callout']['posts']);
    }

    public function test_paginate_rows_filters_tier(): void {
        $agg = new Coverage_Aggregator();
        $summary = $agg->build_summary([
            [
                'post_id' => 1,
                'post_type' => 'post',
                'graph_json' => null,
                'is_complete' => false,
            ],
            [
                'post_id' => 2,
                'post_type' => 'post',
                'graph_json' => $this->graph(['Article']),
                'is_complete' => true,
                'config_ids' => [2],
                'config_placements' => [2 => 'targeted'],
            ],
        ]);
        $page = $agg->paginate_rows($summary['rows'], ['tier' => 'none', 'per_page' => 10]);
        $this->assertSame(1, $page['total']);
        $this->assertSame(1, $page['rows'][0]['post_id']);
    }

    public function test_paginate_rows_filters_issue_reason(): void {
        $agg = new Coverage_Aggregator();
        $summary = $agg->build_summary([
            [
                'post_id' => 1,
                'post_type' => 'post',
                'graph_json' => $this->graph(['Article']),
                'is_complete' => true,
                'config_ids' => [2],
                'config_placements' => [2 => 'targeted'],
                'scan_invalid' => true,
                'scan_reasons' => ['missing_required'],
                'issue_summary' => 'Missing required fields',
            ],
            [
                'post_id' => 2,
                'post_type' => 'post',
                'graph_json' => $this->graph(['Article']),
                'is_complete' => true,
                'config_ids' => [2],
                'config_placements' => [2 => 'targeted'],
                'scan_invalid' => true,
                'scan_reasons' => ['validation_error', 'live_mismatch'],
                'issue_summary' => 'Validation errors; Live preview differs from stored',
            ],
            [
                'post_id' => 3,
                'post_type' => 'post',
                'graph_json' => $this->graph(['Article']),
                'is_complete' => true,
                'config_ids' => [2],
                'config_placements' => [2 => 'targeted'],
            ],
        ]);

        $this->assertSame(1, $summary['issue_reasons']['missing_required']);
        $this->assertSame(1, $summary['issue_reasons']['validation_error']);
        $this->assertSame(1, $summary['issue_reasons']['live_mismatch']);

        $page = $agg->paginate_rows($summary['rows'], [
            'invalid_only' => true,
            'issue_reason' => 'missing_required',
            'per_page' => 10,
        ]);
        $this->assertSame(1, $page['total']);
        $this->assertSame(1, $page['rows'][0]['post_id']);
    }

    public function test_empty_posts_are_not_validation_errors(): void {
        $agg = new Coverage_Aggregator();
        $summary = $agg->build_summary([
            [
                'post_id' => 1,
                'post_type' => 'post',
                'graph_json' => null,
                'is_complete' => false,
            ],
            [
                'post_id' => 2,
                'post_type' => 'post',
                'graph_json' => $this->graph(['Article']),
                'is_complete' => true,
                'config_ids' => [2],
                'config_placements' => [2 => 'targeted'],
                'scan_invalid' => true,
                'scan_reasons' => ['validation_error'],
                'issue_summary' => 'Validation errors',
            ],
        ]);

        $this->assertSame(2, $summary['invalid_posts']);
        $this->assertSame(1, $summary['issue_reasons']['no_schema'] ?? 0);
        $this->assertSame(1, $summary['issue_reasons']['validation_error'] ?? 0);
        $this->assertTrue($summary['rows'][0]['invalid']);
        $this->assertSame(['no_schema'], $summary['rows'][0]['scan_reasons']);
        $this->assertSame('No schema', $summary['rows'][0]['issue_summary']);
        $this->assertTrue($summary['rows'][1]['invalid']);

        $none_only = $agg->paginate_rows($summary['rows'], [
            'invalid_only' => true,
            'issue_reason' => 'no_schema',
            'per_page' => 10,
        ]);
        $this->assertSame(1, $none_only['total']);
        $this->assertSame(1, $none_only['rows'][0]['post_id']);
    }

    public function test_row_titles_are_html_entity_decoded(): void {
        $agg = new Coverage_Aggregator();
        $summary = $agg->build_summary([
            [
                'post_id' => 9,
                'title' => 'Foo &amp; Bar &#039;Baz&#039;',
                'post_type' => 'post',
                'graph_json' => null,
                'is_complete' => false,
            ],
        ]);

        $this->assertSame("Foo & Bar 'Baz'", $summary['rows'][0]['title']);
    }

    public function test_row_viewer_url_targets_page_debugger(): void {
        $agg = new Coverage_Aggregator();
        $summary = $agg->build_summary([
            [
                'post_id' => 9,
                'title' => 'Example',
                'post_type' => 'post',
                'graph_json' => null,
                'is_complete' => false,
            ],
        ]);

        $url = $summary['rows'][0]['viewer_url'];
        $this->assertStringContainsString('tool=checker', $url);
        $this->assertStringNotContainsString('tool=viewer', $url);
        $this->assertStringContainsString('post_id=9', $url);
    }
}
