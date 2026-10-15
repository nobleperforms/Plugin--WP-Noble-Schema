<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Debug\Debug_Url;
use PHPUnit\Framework\TestCase;

class Debug_Url_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_bloginfo'] = ['url' => 'https://example.com'];
    }

    protected function tearDown(): void {
        unset($GLOBALS['noble_schema_test_bloginfo']);
        parent::tearDown();
    }

    public function test_rejects_empty_and_malformed(): void {
        $this->assertSame('empty', Debug_Url::eligibility('')['code']);
        $this->assertSame('invalid', Debug_Url::eligibility('not a url')['code']);
        $this->assertFalse(Debug_Url::eligibility('')['ok']);
    }

    public function test_rejects_off_site_absolute_url(): void {
        $result = Debug_Url::eligibility('https://evil.example/post');
        $this->assertFalse($result['ok']);
        $this->assertSame('off_site', $result['code']);
    }

    public function test_accepts_same_host_ignoring_scheme(): void {
        $result = Debug_Url::eligibility('http://example.com/category/news/');
        $this->assertTrue($result['ok']);
        $this->assertSame('https://example.com/category/news/', $result['normalized']);
    }

    public function test_accepts_www_variant(): void {
        $this->assertTrue(Debug_Url::eligibility('https://www.example.com/about/')['ok']);
    }

    public function test_relative_path_is_same_site(): void {
        $result = Debug_Url::eligibility('/category/news/');
        $this->assertTrue($result['ok']);
        $this->assertSame('https://example.com/category/news/', $result['normalized']);
    }

    public function test_query_only_relative_is_same_site(): void {
        $result = Debug_Url::eligibility('?s=hello');
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('s=hello', $result['normalized']);
    }
}
