<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Tools\Log;
use PHPUnit\Framework\TestCase;

class Log_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
        $GLOBALS['noble_schema_test_logs'] = [];
        $GLOBALS['noble_schema_test_log_seq'] = 0;
    }

    public function test_disabled_info_does_not_insert(): void {
        Log::info('hello', ['category' => 'system']);
        $this->assertSame([], $GLOBALS['noble_schema_test_logs']);
    }

    public function test_enabled_info_inserts_and_query_orders_newest_first(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';
        $GLOBALS['noble_schema_test_options']['ns_logging_retention'] = '3m';

        Log::info('first', ['category' => 'schema_updated', 'post_id' => 12]);
        Log::warning('second', ['category' => 'import_completed']);
        Log::error('third', ['category' => 'regen_failed', 'post_id' => 12]);

        $rows = Log::query();
        $this->assertCount(3, $rows);
        $this->assertSame('third', $rows[0]['message']);
        $this->assertSame('error', $rows[0]['level']);
        $this->assertSame('first', $rows[2]['message']);

        $filters = Log::filter_options();
        $this->assertContains('error', $filters['levels']);
        $this->assertContains('warning', $filters['levels']);
        $this->assertContains('info', $filters['levels']);
        $this->assertContains('schema_updated', $filters['categories']);
        $this->assertArrayNotHasKey('posts', $filters);
    }

    public function test_insert_and_update_keep_single_row(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';

        $id = Log::insert('Schema regen in progress', [
            'category' => 'regen_started',
            'status'   => 'running',
            'post_ids' => [],
        ]);
        $this->assertGreaterThan(0, $id);
        $this->assertCount(1, $GLOBALS['noble_schema_test_logs']);

        $ok = Log::update($id, 'Schema regen completed', [
            'category' => 'regen_completed',
            'status'   => 'completed',
            'post_ids' => [11, 22, 33],
            'updated'  => 3,
        ]);
        $this->assertTrue($ok);
        $this->assertCount(1, $GLOBALS['noble_schema_test_logs']);
        $this->assertSame('Schema regen completed', $GLOBALS['noble_schema_test_logs'][0]['message']);
        $this->assertSame('regen_completed', $GLOBALS['noble_schema_test_logs'][0]['category']);
        $context = json_decode((string) $GLOBALS['noble_schema_test_logs'][0]['context'], true);
        $this->assertSame([11, 22, 33], $context['post_ids']);
        $this->assertSame('completed', $context['status']);
    }

    public function test_running_regen_sorts_above_newer_rows(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';
        $GLOBALS['noble_schema_test_logs'] = [
            [
                'id' => 1,
                'created_at' => '2026-08-01 10:00:00',
                'level' => 'info',
                'category' => 'regen_started',
                'post_id' => null,
                'message' => 'in progress',
                'context' => wp_json_encode(['status' => 'running', 'post_ids' => [1]]),
            ],
            [
                'id' => 2,
                'created_at' => '2026-08-01 10:01:00',
                'level' => 'info',
                'category' => 'schema_updated',
                'post_id' => 1,
                'message' => 'updated',
                'context' => '{}',
            ],
            [
                'id' => 3,
                'created_at' => '2026-08-01 10:02:00',
                'level' => 'info',
                'category' => 'schema_updated',
                'post_id' => 2,
                'message' => 'updated',
                'context' => '{}',
            ],
        ];

        $rows = Log::query(['limit' => 10]);
        $this->assertSame([1, 3, 2], array_column($rows, 'id'));
    }

    public function test_query_supports_before_id_and_date_range(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';
        $GLOBALS['noble_schema_test_options']['timezone_string'] = 'UTC';

        $GLOBALS['noble_schema_test_logs'] = [
            ['id' => 1, 'created_at' => '2026-08-01 10:00:00', 'level' => 'info', 'category' => 'system', 'post_id' => null, 'message' => 'a', 'context' => '{}'],
            ['id' => 2, 'created_at' => '2026-08-02 10:00:00', 'level' => 'info', 'category' => 'system', 'post_id' => null, 'message' => 'b', 'context' => '{}'],
            ['id' => 3, 'created_at' => '2026-08-03 10:00:00', 'level' => 'info', 'category' => 'system', 'post_id' => null, 'message' => 'c', 'context' => '{}'],
            ['id' => 4, 'created_at' => '2026-08-04 10:00:00', 'level' => 'info', 'category' => 'system', 'post_id' => null, 'message' => 'd', 'context' => '{}'],
        ];
        $GLOBALS['noble_schema_test_log_seq'] = 4;

        $page1 = Log::query(['limit' => 2]);
        $this->assertSame([4, 3], array_column($page1, 'id'));

        $page2 = Log::query(['before_id' => 3, 'limit' => 2]);
        $this->assertSame([2, 1], array_column($page2, 'id'));

        $day = Log::query(['date_from' => '2026-08-02', 'date_to' => '2026-08-03', 'limit' => 50]);
        $this->assertSame([3, 2], array_column($day, 'id'));
    }

    public function test_schema_change_includes_before_and_after_graphs(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';

        $before = wp_json_encode(['@graph' => [['@type' => 'WebPage', 'name' => 'Old']]]);
        $after = wp_json_encode(['@graph' => [['@type' => 'Article', 'name' => 'New']]]);

        Log::schema_change(42, 'updated', [1], ['WebPage'], [2], ['Article'], $before, $after);

        $this->assertCount(1, $GLOBALS['noble_schema_test_logs']);
        $this->assertSame('schema_updated', $GLOBALS['noble_schema_test_logs'][0]['category']);
        $context = json_decode((string) $GLOBALS['noble_schema_test_logs'][0]['context'], true);
        $this->assertSame(['WebPage'], $context['before']['types']);
        $this->assertSame(['Article'], $context['after']['types']);
        $this->assertSame('Old', $context['before']['graph']['@graph'][0]['name']);
        $this->assertSame('New', $context['after']['graph']['@graph'][0]['name']);
    }

    public function test_for_post_matches_post_id_and_context_post_ids(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';
        $GLOBALS['noble_schema_test_logs'] = [
            ['id' => 1, 'created_at' => '2026-08-01 10:00:00', 'level' => 'info', 'category' => 'schema_updated', 'post_id' => 12, 'message' => 'direct', 'context' => '{}'],
            ['id' => 2, 'created_at' => '2026-08-02 10:00:00', 'level' => 'info', 'category' => 'regen_started', 'post_id' => null, 'message' => 'batch', 'context' => wp_json_encode(['post_ids' => [5, 12, 99]])],
            ['id' => 3, 'created_at' => '2026-08-03 10:00:00', 'level' => 'info', 'category' => 'system', 'post_id' => 99, 'message' => 'other', 'context' => '{}'],
        ];

        $rows = Log::for_post(12, 20);
        $this->assertSame([2, 1], array_column($rows, 'id'));
    }

    public function test_created_at_displayed_in_site_timezone(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';
        $GLOBALS['noble_schema_test_options']['timezone_string'] = 'America/Los_Angeles';

        Log::info('tz check', ['category' => 'system']);
        $rows = Log::query();

        // Stub current_time stores 2026-08-04 12:00:00 as GMT → 5:00am PDT.
        $this->assertSame('August 4, 2026 5:00am PDT', $rows[0]['created_at']);
    }

    public function test_purge_all_clears_rows(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';
        Log::info('x', ['category' => 'system']);
        $this->assertSame(1, Log::purge_all());
        $this->assertSame([], $GLOBALS['noble_schema_test_logs']);
    }

    public function test_sanitize_retention_defaults(): void {
        $this->assertSame('3m', Log::sanitize_retention('nope'));
        $this->assertSame('1d', Log::sanitize_retention('1d'));
    }

    public function test_types_from_graph_json(): void {
        $json = wp_json_encode([
            '@graph' => [
                ['@type' => 'Article'],
                ['@type' => ['WebPage', 'ItemPage']],
            ],
        ]);
        $types = Log::types_from_graph_json($json);
        $this->assertSame(['Article', 'WebPage', 'ItemPage'], $types);
    }
}
