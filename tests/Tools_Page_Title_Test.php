<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Admin\Tools_Page;
use PHPUnit\Framework\TestCase;

class Tools_Page_Title_Test extends TestCase {
    protected function setUp(): void {
        $_GET = [];
        $GLOBALS['noble_schema_test_bloginfo'] = ['name' => 'Travel Nevada'];
    }

    protected function tearDown(): void {
        $_GET = [];
    }

    public function test_hub_document_title(): void {
        $this->assertSame(
            'Schema Tools &lsaquo; Travel Nevada',
            Tools_Page::document_title('')
        );
    }

    public function test_tool_document_title(): void {
        $this->assertSame(
            'Schema Storefront &lsaquo; Schema Tools &lsaquo; Travel Nevada',
            Tools_Page::document_title('storefront')
        );
        $this->assertSame(
            'Sitewide Analytics &lsaquo; Schema Tools &lsaquo; Travel Nevada',
            Tools_Page::document_title('sitewide-analytics')
        );
    }

    public function test_current_tool_slug_from_query(): void {
        $_GET['tool'] = 'checker';
        $this->assertSame('checker', Tools_Page::current_tool_slug());

        $_GET = ['tool' => 'sitewide-analytics'];
        $this->assertSame('sitewide-analytics', Tools_Page::current_tool_slug());

        $_GET = ['post_id' => '42'];
        $this->assertSame('checker', Tools_Page::current_tool_slug());

        $_GET = ['tool' => 'not-a-tool'];
        $this->assertSame('', Tools_Page::current_tool_slug());
    }

    public function test_viewer_is_not_a_tool(): void {
        $this->assertArrayNotHasKey('viewer', Tools_Page::tool_titles());
        $this->assertArrayHasKey('checker', Tools_Page::tool_titles());

        $_GET = ['tool' => 'viewer'];
        $this->assertSame('', Tools_Page::current_tool_slug());
    }

    public function test_all_registered_tools_have_titles(): void {
        foreach (array_keys(Tools_Page::tool_titles()) as $slug) {
            $title = Tools_Page::document_title($slug, 'Example Site');
            $this->assertStringContainsString('Schema Tools', $title);
            $this->assertStringContainsString('Example Site', $title);
            $this->assertStringContainsString(Tools_Page::tool_titles()[$slug], $title);
        }
    }
}
