<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Coverage\Coverage_Scan_Queue;
use PHPUnit\Framework\TestCase;

class Coverage_Scan_Queue_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
    }

    public function test_start_process_and_complete(): void {
        Coverage_Scan_Queue::start([10, 20, 30]);
        $status = Coverage_Scan_Queue::get_status();
        $this->assertSame('running', $status['status']);
        $this->assertSame(3, $status['total']);
        $this->assertSame(0, $status['processed']);

        Coverage_Scan_Queue::process_batch(2);
        $status = Coverage_Scan_Queue::get_status();
        $this->assertSame(2, $status['processed']);
        $this->assertSame('running', $status['status']);

        Coverage_Scan_Queue::process_batch(5);
        $status = Coverage_Scan_Queue::get_status();
        $this->assertSame('completed', $status['status']);
        $this->assertTrue($status['done']);
        $this->assertSame(3, $status['processed']);

        $results = Coverage_Scan_Queue::get_results();
        $this->assertNotNull($results);
        $this->assertArrayHasKey(10, $results['posts']);
        $this->assertArrayHasKey(20, $results['posts']);
        $this->assertArrayHasKey(30, $results['posts']);
    }

    public function test_cancel_stops_job(): void {
        Coverage_Scan_Queue::start([1, 2]);
        Coverage_Scan_Queue::cancel();
        $status = Coverage_Scan_Queue::get_status();
        $this->assertSame('cancelled', $status['status']);
        $this->assertTrue($status['cancelled']);
    }
}
