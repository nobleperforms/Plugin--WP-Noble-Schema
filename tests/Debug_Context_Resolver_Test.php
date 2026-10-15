<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Debug\Debug_Context_Resolver;
use PHPUnit\Framework\TestCase;

class Debug_Context_Resolver_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_bloginfo'] = ['url' => 'https://example.com'];
        $GLOBALS['noble_schema_test_url_to_postid'] = [];
        $GLOBALS['noble_schema_test_posts'] = [
            12 => new \WP_Post([
                'ID' => 12,
                'post_title' => 'Hello',
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_content' => '',
            ]),
        ];
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['noble_schema_test_bloginfo'],
            $GLOBALS['noble_schema_test_url_to_postid'],
            $GLOBALS['noble_schema_test_posts']
        );
        parent::tearDown();
    }

    public function test_from_url_rejects_off_site(): void {
        $result = Debug_Context_Resolver::from_url('https://other.test/foo');
        $this->assertFalse($result['ok']);
        $this->assertSame('off_site', $result['code']);
    }

    public function test_from_url_resolves_post_via_url_to_postid(): void {
        $GLOBALS['noble_schema_test_url_to_postid'] = [
            'https://example.com/hello' => 12,
            'https://example.com/hello/' => 12,
        ];
        $result = Debug_Context_Resolver::from_url('https://example.com/hello/');
        $this->assertTrue($result['ok']);
        $this->assertSame('singular', $result['context']['type']);
        $this->assertSame(12, $result['context']['post_id']);
    }

    public function test_from_url_resolves_search_query(): void {
        $result = Debug_Context_Resolver::from_url('https://example.com/?s=nevada');
        $this->assertTrue($result['ok']);
        $this->assertSame('specialty', $result['context']['type']);
        $this->assertSame('search', $result['context']['specialty_key']);
    }

    public function test_from_request_specialty_404(): void {
        $result = Debug_Context_Resolver::from_request([
            'context'       => 'specialty',
            'specialty_key' => '404',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('specialty', $result['context']['type']);
        $this->assertSame('404', $result['context']['specialty_key']);
    }

    public function test_from_request_singular_ignores_empty_specialty_and_archive_keys(): void {
        $result = Debug_Context_Resolver::from_request([
            'url'           => '',
            'context'       => 'singular',
            'post_id'       => 12,
            'archive_key'   => '',
            'specialty_key' => '',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('singular', $result['context']['type']);
        $this->assertSame(12, $result['context']['post_id']);
    }

    public function test_from_request_prefers_explicit_post_id_over_echoed_url(): void {
        $result = Debug_Context_Resolver::from_request([
            'url'           => 'https://example.com/hello/',
            'context'       => 'singular',
            'post_id'       => 12,
            'archive_key'   => '',
            'specialty_key' => '',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('singular', $result['context']['type']);
        $this->assertSame(12, $result['context']['post_id']);
    }
}
