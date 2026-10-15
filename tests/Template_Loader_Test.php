<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Templates\Template_Loader;
use PHPUnit\Framework\TestCase;

class Template_Loader_Test extends TestCase {
    public function test_loads_faqpage_template(): void {
        $loader = new Template_Loader(dirname(__DIR__) . '/templates');
        $faq = $loader->get('faqpage');
        $this->assertNotNull($faq);
        $this->assertSame('FAQPage', $faq['schema_type']);
        $this->assertTrue($loader->validate($faq));
    }

    public function test_rejects_invalid_template(): void {
        $loader = new Template_Loader(sys_get_temp_dir());
        $this->assertFalse($loader->validate(['slug' => 'x']));
    }
}
