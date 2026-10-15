<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Schema\Schema_Validator;
use PHPUnit\Framework\TestCase;

class Schema_Validator_Test extends TestCase {
    public function test_empty_is_error(): void {
        $result = Schema_Validator::validate(null);
        $this->assertFalse($result['ok']);
        $this->assertSame('empty', $result['issues'][0]['code']);
    }

    public function test_invalid_json(): void {
        $result = Schema_Validator::validate('{nope');
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_json', $result['issues'][0]['code']);
    }

    public function test_valid_document(): void {
        $json = wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'WebPage',
                    '@id'   => 'https://example.com/#WebPage',
                    'name'  => 'Home',
                ],
            ],
        ]);
        $result = Schema_Validator::validate($json);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['error_count']);
        $this->assertSame(0, $result['warning_count']);
    }

    public function test_missing_type_and_id(): void {
        $json = wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                ['name' => 'No type'],
            ],
        ]);
        $result = Schema_Validator::validate($json);
        $this->assertFalse($result['ok']);
        $codes = array_column($result['issues'], 'code');
        $this->assertContains('missing_type', $codes);
        $this->assertContains('missing_id', $codes);
        $this->assertSame(1, $result['error_count']);
        $this->assertSame(1, $result['warning_count']);
    }

    public function test_empty_graph(): void {
        $json = wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [],
        ]);
        $result = Schema_Validator::validate($json);
        $this->assertFalse($result['ok']);
        $this->assertSame('empty_graph', $result['issues'][0]['code']);
    }
}
