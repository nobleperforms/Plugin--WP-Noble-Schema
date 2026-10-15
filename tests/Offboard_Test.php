<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Offboard\Offboard;
use NobleSchemaLite\Migrator;
use PHPUnit\Framework\TestCase;

class Offboard_Test extends TestCase {
    protected function setUp(): void {
        if (!defined('NS_LITE_META_KEY')) {
            define('NS_LITE_META_KEY', '_ns_lite_schema');
        }
        if (!defined('NS_LITE_ARCHIVE_OPTION')) {
            define('NS_LITE_ARCHIVE_OPTION', 'ns_lite_archive_schema');
        }
        require_once dirname(__DIR__) . '/offboard/wp-noble-schema-lite/includes/Migrator.php';
    }

    public function test_confirm_phrase_and_paths(): void {
        $this->assertSame('OFFBOARD', Offboard::CONFIRM_PHRASE);
        $this->assertSame('wp-noble-schema-lite/wp-noble-schema-lite.php', Offboard::LITE_MAIN);
        $this->assertDirectoryExists(Offboard::bundle_dir());
        $this->assertFileExists(Offboard::bundle_dir() . '/wp-noble-schema-lite.php');
    }

    public function test_migrator_archives_from_cache_option(): void {
        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';

            public function prepare($query, ...$args) {
                return $args[0] ?? $query;
            }

            public function get_var($query) {
                return null;
            }

            public function get_results($query) {
                return [];
            }
        };

        $GLOBALS['noble_schema_test_options'] = [
            'ns_archive_schema_cache' => [
                'home' => [
                    'graph_json'   => '{"@type":"CollectionPage"}',
                    'config_ids'   => [1],
                    'generated_at' => '2026-01-01 00:00:00',
                ],
                'post_type:event' => [
                    'graph_json' => '',
                    'config_ids' => [],
                ],
                'post_type:page' => '{"@type":"CollectionPage","name":"Pages"}',
            ],
        ];

        $counts = (new Migrator())->migrate();

        $this->assertSame(0, $counts['posts']);
        $this->assertSame(2, $counts['archives']);
        $this->assertSame(
            [
                'home' => '{"@type":"CollectionPage"}',
                'post_type:page' => '{"@type":"CollectionPage","name":"Pages"}',
            ],
            $GLOBALS['noble_schema_test_options']['ns_lite_archive_schema']
        );
    }
}
