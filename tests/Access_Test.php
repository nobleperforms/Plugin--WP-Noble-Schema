<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Admin\Access;
use NobleSchema\Admin\Settings_Page;
use PHPUnit\Framework\TestCase;

class Access_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
        $GLOBALS['noble_schema_test_current_user_id'] = 1;
        $GLOBALS['noble_schema_test_user_caps'] = [
            1 => ['manage_options' => true],
            2 => ['manage_options' => true],
            3 => ['manage_options' => true],
            4 => ['edit_posts' => true],
        ];
    }

    public function test_unrestricted_allows_any_manage_options_user(): void {
        $this->assertTrue(Access::can_manage_schema(1));
        $this->assertTrue(Access::can_manage_schema(2));
        $this->assertFalse(Access::can_manage_schema(4));
    }

    public function test_restricted_allowlist(): void {
        $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_RESTRICT] = '1';
        $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_ADMIN_USER_IDS] = [1, 3];

        $this->assertTrue(Access::can_manage_schema(1));
        $this->assertFalse(Access::can_manage_schema(2));
        $this->assertTrue(Access::can_manage_schema(3));
        $this->assertFalse(Access::can_manage_schema(4));
    }

    public function test_restricted_empty_allowlist_auto_disables(): void {
        $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_RESTRICT] = '1';
        $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_ADMIN_USER_IDS] = [];

        $this->assertFalse(Access::restriction_enabled());
        $this->assertArrayNotHasKey(Settings_Page::OPT_RESTRICT, $GLOBALS['noble_schema_test_options']);
        $this->assertTrue(Access::can_manage_schema(1));
    }

    public function test_sanitize_user_ids_force_includes_current_without_cap_recheck(): void {
        $GLOBALS['noble_schema_test_current_user_id'] = 4; // edit_posts only
        $ids = Access::sanitize_user_ids([], true);
        $this->assertSame([4], $ids);
    }

    public function test_sanitize_user_ids_filters_and_forces_current(): void {
        $GLOBALS['noble_schema_test_current_user_id'] = 2;
        $ids = Access::sanitize_user_ids([2, 2, 4, '3', 0, -1], true);
        $this->assertSame([2, 3], $ids);
    }

    public function test_sanitize_user_ids_without_force_skips_current(): void {
        $GLOBALS['noble_schema_test_current_user_id'] = 1;
        $ids = Access::sanitize_user_ids([3], false);
        $this->assertSame([3], $ids);
    }
}
