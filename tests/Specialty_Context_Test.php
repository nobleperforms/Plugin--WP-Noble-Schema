<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Targeting\Specialty_Context;
use PHPUnit\Framework\TestCase;

class Specialty_Context_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
        $GLOBALS['noble_schema_test_is_admin'] = false;
        $GLOBALS['noble_schema_test_is_404'] = false;
        $GLOBALS['noble_schema_test_is_search'] = false;
        unset($GLOBALS['noble_schema_test_woocommerce']);
    }

    public function test_core_keys_present_without_woocommerce(): void {
        $defs = Specialty_Context::definitions();
        foreach (['front_page', 'posts_page', 'privacy_policy', '404', 'search'] as $key) {
            $this->assertArrayHasKey($key, $defs);
        }
        $this->assertArrayNotHasKey('wc_shop', $defs);
        $this->assertTrue(Specialty_Context::is_virtual('404'));
        $this->assertFalse(Specialty_Context::is_virtual('front_page'));
        $this->assertSame(
            array_column(Specialty_Context::available(), 'value'),
            Specialty_Context::all_targeting_keys()
        );
    }

    public function test_resolve_front_page_requires_static_front(): void {
        $GLOBALS['noble_schema_test_options']['show_on_front'] = 'posts';
        $GLOBALS['noble_schema_test_options']['page_on_front'] = 12;
        $this->assertSame(0, Specialty_Context::resolve_post_id('front_page'));

        $GLOBALS['noble_schema_test_options']['show_on_front'] = 'page';
        $this->assertSame(12, Specialty_Context::resolve_post_id('front_page'));
    }

    public function test_virtual_keys_do_not_resolve_to_posts(): void {
        $this->assertSame(0, Specialty_Context::resolve_post_id('404'));
        $this->assertSame(0, Specialty_Context::resolve_post_id('search'));
    }

    public function test_available_marks_unset_page_backed(): void {
        $options = Specialty_Context::available();
        $by_value = [];
        foreach ($options as $opt) {
            $by_value[$opt['value']] = $opt;
        }
        $this->assertStringContainsString('(not set)', $by_value['front_page']['label']);
        $this->assertSame('virtual', $by_value['404']['kind']);
    }

    public function test_current_key_for_virtual_routes(): void {
        $this->assertNull(Specialty_Context::current_key());

        $GLOBALS['noble_schema_test_is_404'] = true;
        $this->assertSame('404', Specialty_Context::current_key());

        $GLOBALS['noble_schema_test_is_404'] = false;
        $GLOBALS['noble_schema_test_is_search'] = true;
        $this->assertSame('search', Specialty_Context::current_key());

        $GLOBALS['noble_schema_test_is_admin'] = true;
        $this->assertNull(Specialty_Context::current_key());
    }

    public function test_context_from_key_sets_specialty_fields(): void {
        $ctx = Specialty_Context::context_from_key('404');
        $this->assertSame('404', $ctx['specialty_key']);
        $this->assertSame(0, $ctx['post_id']);
        $this->assertTrue($ctx['is_specialty']);
        $this->assertNotSame('', $ctx['specialty_title']);
    }
}
