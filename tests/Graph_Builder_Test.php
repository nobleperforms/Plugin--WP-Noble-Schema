<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Generator\Graph_Builder;
use PHPUnit\Framework\TestCase;

class Graph_Builder_Test extends TestCase {
    public function test_graph_builder_wraps_nodes(): void {
        $b = new Graph_Builder();
        $doc = $b->build([['@type' => 'Organization', 'name' => 'Travel Nevada']]);
        $this->assertSame('https://schema.org', $doc['@context']);
        $this->assertCount(1, $doc['@graph']);
    }
}
