<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Cache\Sitewide_Cache;
use PHPUnit\Framework\TestCase;

class Sitewide_Cache_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
    }

    public function test_set_get_and_delete(): void {
        $cache = new Sitewide_Cache();
        $cache->set(7, '{"@graph":[{"@type":"Organization"}]}');

        $hit = $cache->get(7);
        $this->assertNotNull($hit);
        $this->assertSame('{"@graph":[{"@type":"Organization"}]}', $hit['graph_json']);
        $this->assertNotSame('', $hit['generated_at']);

        $cache->delete(7);
        $this->assertNull($cache->get(7));
    }

    public function test_clear_all(): void {
        $cache = new Sitewide_Cache();
        $cache->set(1, '{"a":1}');
        $cache->set(2, '{"b":2}');
        $cache->clear_all();
        $this->assertNull($cache->get(1));
        $this->assertNull($cache->get(2));
    }
}
