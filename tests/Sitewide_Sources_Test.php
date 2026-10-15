<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Targeting\Sitewide_Sources;
use PHPUnit\Framework\TestCase;

class Sitewide_Sources_Test extends TestCase {
    public function test_allows_site_info_and_static_only(): void {
        $this->assertTrue(Sitewide_Sources::is_allowed('site_info'));
        $this->assertTrue(Sitewide_Sources::is_allowed('static'));
        $this->assertFalse(Sitewide_Sources::is_allowed('core'));
        $this->assertTrue(Sitewide_Sources::is_blocked('core'));
        $this->assertTrue(Sitewide_Sources::is_blocked('acf'));
        $this->assertTrue(Sitewide_Sources::is_blocked('unknown_source'));
        $this->assertFalse(Sitewide_Sources::is_blocked('site_info'));
    }

    public function test_blocked_in_maps_dedupes_types(): void {
        $blocked = Sitewide_Sources::blocked_in_maps([
            ['source_type' => 'core'],
            (object) ['source_type' => 'static'],
            (object) ['source_type' => 'acf'],
            ['source_type' => 'core'],
            ['source_type' => ''],
        ]);

        $this->assertSame(['core', 'acf'], $blocked);
    }
}
