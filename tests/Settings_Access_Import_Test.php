<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Admin\Access;
use NobleSchema\Admin\Settings_Page;
use PHPUnit\Framework\TestCase;

class Settings_Access_Import_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [
            Settings_Page::OPT_ADMIN_USER_IDS => [9, 8],
        ];
        $GLOBALS['noble_schema_test_current_user_id'] = 1;
        $GLOBALS['noble_schema_test_user_caps'] = [
            1 => ['manage_options' => true],
            8 => ['manage_options' => true],
            9 => ['manage_options' => true],
        ];
    }

    public function test_exportable_settings_omits_user_ids(): void {
        $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_RESTRICT] = '1';
        $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_ADMIN_USER_IDS] = [1, 2];

        $exported = Settings_Page::exportable_settings();
        $this->assertSame('1', $exported[Settings_Page::OPT_RESTRICT]);
        $this->assertArrayNotHasKey(Settings_Page::OPT_ADMIN_USER_IDS, $exported);
    }

    public function test_import_restrict_on_seeds_current_user(): void {
        Settings_Page::import_settings([
            Settings_Page::OPT_RESTRICT => '1',
        ]);

        $this->assertSame('1', $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_RESTRICT]);
        $this->assertSame([1], $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_ADMIN_USER_IDS]);
    }

    public function test_import_restrict_on_without_current_user_disables_restriction(): void {
        $GLOBALS['noble_schema_test_current_user_id'] = 0;

        Settings_Page::import_settings([
            Settings_Page::OPT_RESTRICT => '1',
        ]);

        $this->assertSame('', $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_RESTRICT]);
    }

    public function test_import_restrict_off_keeps_local_allowlist(): void {
        Settings_Page::import_settings([
            Settings_Page::OPT_RESTRICT => '',
        ]);

        $this->assertSame('', $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_RESTRICT]);
        $this->assertSame([9, 8], $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_ADMIN_USER_IDS]);
    }

    public function test_settings_change_log_lists_changed_labels(): void {
        $GLOBALS['noble_schema_test_options']['ns_logging_enabled'] = '1';
        $GLOBALS['noble_schema_test_logs'] = [];
        $GLOBALS['noble_schema_test_log_seq'] = 0;

        Settings_Page::queue_setting_change(Settings_Page::OPT_USERNAME);
        Settings_Page::queue_setting_change(Settings_Page::OPT_LOGGING_RETENTION);
        Settings_Page::flush_settings_change_log();

        $this->assertCount(1, $GLOBALS['noble_schema_test_logs']);
        $this->assertSame('settings_updated', $GLOBALS['noble_schema_test_logs'][0]['category']);
        $this->assertStringContainsString('GitHub username', $GLOBALS['noble_schema_test_logs'][0]['message']);
        $this->assertStringContainsString('Log retention', $GLOBALS['noble_schema_test_logs'][0]['message']);
        $context = json_decode((string) $GLOBALS['noble_schema_test_logs'][0]['context'], true);
        $this->assertSame(
            [Settings_Page::OPT_USERNAME, Settings_Page::OPT_LOGGING_RETENTION],
            $context['changed']
        );
    }

    public function test_sanitize_sitewide_schema_types_always_keeps_defaults(): void {
        $this->assertSame(
            'Organization,WebSite,WebPage',
            Settings_Page::sanitize_sitewide_schema_types('')
        );
        $this->assertSame(
            'Organization,WebSite,WebPage,BreadcrumbList',
            Settings_Page::sanitize_sitewide_schema_types(" Organization , BreadcrumbList , Organization \n")
        );
    }

    public function test_sanitize_sitewide_schema_types_accepts_arrays(): void {
        $this->assertSame(
            'Organization,WebSite,WebPage,BreadcrumbList',
            Settings_Page::sanitize_sitewide_schema_types(['BreadcrumbList', 'Organization', ''])
        );
    }

    public function test_sitewide_schema_types_reads_option_or_defaults(): void {
        unset($GLOBALS['noble_schema_test_options'][Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES]);
        $this->assertSame(
            ['Organization', 'WebSite', 'WebPage'],
            Settings_Page::sitewide_schema_types()
        );

        $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES] = 'Organization,BreadcrumbList';
        $this->assertSame(
            ['Organization', 'WebSite', 'WebPage', 'BreadcrumbList'],
            Settings_Page::sitewide_schema_types()
        );
    }

    public function test_export_and_import_sitewide_schema_types(): void {
        $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES] = 'Organization,BreadcrumbList';
        $exported = Settings_Page::exportable_settings();
        $this->assertSame(
            'Organization,WebSite,WebPage,BreadcrumbList',
            $exported[Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES]
        );

        Settings_Page::import_settings([
            Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES => ['LocalBusiness'],
        ]);
        $this->assertSame(
            'Organization,WebSite,WebPage,LocalBusiness',
            $GLOBALS['noble_schema_test_options'][Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES]
        );
    }

    public function test_build_sitewide_type_options_lists_defaults_then_templates(): void {
        $options = Settings_Page::build_sitewide_type_options(
            [
                'faqpage' => ['label' => 'FAQ Page', 'schema_type' => 'FAQPage'],
                'article' => ['label' => 'Article', 'schema_type' => 'Article'],
                'blank'   => ['label' => 'Broken', 'schema_type' => ''],
            ],
            ['BreadcrumbList']
        );

        $this->assertSame(
            ['Organization', 'WebSite', 'WebPage', 'Article', 'FAQPage', 'BreadcrumbList'],
            array_column($options, 'value')
        );
        $this->assertSame('FAQPage (FAQ Page)', $options[4]['label']);
        $this->assertSame('Article', $options[3]['label']);
    }
}
