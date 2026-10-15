<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Cache\Archive_Cache;
use PHPUnit\Framework\TestCase;

class Archive_Cache_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
    }

    public function test_set_get_and_clear_for_config(): void {
        $cache = new Archive_Cache();
        $cache->set('home', '{"@graph":[]}', [12, 34]);
        $cache->set('post_type:tribe_events', '{"@graph":[{"@type":"Event"}]}', [12]);

        $home = $cache->get('home');
        $this->assertNotNull($home);
        $this->assertSame('{"@graph":[]}', $home['graph_json']);
        $this->assertSame([12, 34], $home['config_ids']);

        $this->assertSame(2, $cache->count_for_config(12));
        $this->assertSame(1, $cache->count_for_config(34));
        $this->assertSame(0, $cache->count_for_config(99));

        $removed = $cache->clear_for_config(12);
        $this->assertSame(2, $removed);
        $this->assertSame(0, $cache->count_for_config(12));
        $this->assertNull($cache->get('home'));
        $this->assertNull($cache->get('post_type:tribe_events'));
    }
}
