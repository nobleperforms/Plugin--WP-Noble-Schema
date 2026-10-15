<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Schema\Schema_Validator;
use PHPUnit\Framework\TestCase;

class Schema_Validator_Snapshot_Test extends TestCase {
    public function test_missing_snapshot_validates_null(): void {
        $expected = Schema_Validator::validate(null);

        $this->assertSame($expected, Schema_Validator::validate_snapshot(null));
        $this->assertSame($expected, Schema_Validator::validate_snapshot([]));
        $this->assertSame(
            $expected,
            Schema_Validator::validate_snapshot(['present' => false, 'graph_json' => '{}'])
        );
    }

    public function test_present_snapshot_validates_graph_json(): void {
        $graph = '{"@context":"https://schema.org","@graph":[{"@type":"Article","name":"Hi"}]}';

        $this->assertSame(
            Schema_Validator::validate($graph),
            Schema_Validator::validate_snapshot(['present' => true, 'graph_json' => $graph])
        );
    }

    public function test_non_string_graph_json_validates_null(): void {
        $this->assertSame(
            Schema_Validator::validate(null),
            Schema_Validator::validate_snapshot(['present' => true, 'graph_json' => ['not', 'a', 'string']])
        );
    }
}
