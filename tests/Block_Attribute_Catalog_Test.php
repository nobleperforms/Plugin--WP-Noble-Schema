<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Mapping\Block_Attribute_Catalog;
use PHPUnit\Framework\TestCase;

class Block_Attribute_Catalog_Test extends TestCase {
    public function test_attribute_names_sorted_unique(): void {
        $this->assertSame(
            ['align', 'content'],
            Block_Attribute_Catalog::attribute_names([
                'content' => ['type' => 'string'],
                'align'   => ['type' => 'string'],
                ''        => ['type' => 'string'],
            ])
        );
    }

    public function test_attribute_names_handles_empty(): void {
        $this->assertSame([], Block_Attribute_Catalog::attribute_names(null));
        $this->assertSame([], Block_Attribute_Catalog::attribute_names([]));
    }
}
