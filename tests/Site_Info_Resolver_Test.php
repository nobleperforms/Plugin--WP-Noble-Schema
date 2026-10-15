<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Mapping\Site_Info_Resolver;
use PHPUnit\Framework\TestCase;

class Site_Info_Resolver_Test extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['noble_schema_test_bloginfo'] = [
            'name' => 'Travel Nevada',
            'description' => 'A world within. A state apart.',
            'url' => 'https://example.com',
            'charset' => 'UTF-8',
            'language' => 'en-US',
            'rss2_url' => 'https://example.com/feed/',
            'atom_url' => 'https://example.com/feed/atom/',
        ];
        $GLOBALS['noble_schema_test_options']['admin_email'] = 'hello@example.com';
        $GLOBALS['noble_schema_test_options']['date_format'] = 'F j, Y';
        $GLOBALS['noble_schema_test_options']['time_format'] = 'g:i a';
        $GLOBALS['noble_schema_test_options']['timezone_string'] = 'America/Los_Angeles';
    }

    public function test_resolves_site_name(): void {
        $resolver = new Site_Info_Resolver();
        $this->assertSame('Travel Nevada', $resolver->resolve(['field' => 'site_name'], []));
    }

    public function test_resolves_admin_email(): void {
        $resolver = new Site_Info_Resolver();
        $this->assertSame('hello@example.com', $resolver->resolve(['field' => 'admin_email'], []));
    }

    public function test_rejects_unknown_field(): void {
        $resolver = new Site_Info_Resolver();
        $this->assertNull($resolver->resolve(['field' => 'not_real'], []));
    }

    public function test_fields_list_is_non_empty(): void {
        $this->assertNotEmpty(Site_Info_Resolver::fields());
        $this->assertArrayHasKey('site_name', Site_Info_Resolver::fields());
        $this->assertArrayHasKey('home_url', Site_Info_Resolver::fields());
    }
}
