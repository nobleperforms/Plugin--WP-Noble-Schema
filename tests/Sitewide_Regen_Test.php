<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Generator\Regen_Queue;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class Sitewide_Regen_Test extends TestCase {
    public function test_sitewide_does_not_scan_all_public(): void {
        $queue = new Regen_Queue();
        $method = new ReflectionMethod(Regen_Queue::class, 'should_scan_all_public');

        $sitewide = (object) ['placement' => 'sitewide', 'status' => 'active', 'id' => 1];
        $global = (object) ['placement' => 'global', 'status' => 'active', 'id' => 2];
        $targeted = (object) ['placement' => 'post_type', 'status' => 'active', 'id' => 3];

        $this->assertFalse($method->invoke($queue, $sitewide, null, []));
        $this->assertFalse($method->invoke($queue, $sitewide, $sitewide, []));
        $this->assertTrue($method->invoke($queue, $global, null, []));
        $this->assertTrue($method->invoke($queue, $sitewide, $global, []));
        $this->assertTrue($method->invoke($queue, $global, $sitewide, []));
        $this->assertFalse($method->invoke($queue, $targeted, $sitewide, []));
    }
}
