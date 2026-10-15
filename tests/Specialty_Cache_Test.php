<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Cache\Specialty_Cache;
use PHPUnit\Framework\TestCase;

class Specialty_Cache_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
    }

    public function test_set_get_and_clear_for_config(): void {
        $cache = new Specialty_Cache();
        $cache->set('404', '{"@graph":[]}', [12, 34]);
        $cache->set('search', '{"@graph":[{"@type":"WebPage"}]}', [12]);

        $hit = $cache->get('404');
        $this->assertNotNull($hit);
        $this->assertSame('{"@graph":[]}', $hit['graph_json']);
        $this->assertSame([12, 34], $hit['config_ids']);

        $this->assertSame(2, $cache->count_for_config(12));
        $removed = $cache->clear_for_config(12);
        $this->assertSame(2, $removed);
        $this->assertNull($cache->get('404'));
        $this->assertNull($cache->get('search'));
    }
}
