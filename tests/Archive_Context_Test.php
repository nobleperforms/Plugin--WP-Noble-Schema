<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Targeting\Archive_Context;
use PHPUnit\Framework\TestCase;

class Archive_Context_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [
            'page_for_posts' => 10,
        ];
        $GLOBALS['noble_schema_test_post_titles'] = [10 => 'Blog'];
        $GLOBALS['noble_schema_test_is_admin'] = false;
        $GLOBALS['noble_schema_test_is_home'] = false;
        $GLOBALS['noble_schema_test_is_front_page'] = false;
        $GLOBALS['noble_schema_test_is_post_type_archive'] = false;
        $GLOBALS['noble_schema_test_is_category'] = false;
        $GLOBALS['noble_schema_test_is_tag'] = false;
        $GLOBALS['noble_schema_test_is_tax'] = false;
        $GLOBALS['noble_schema_test_is_author'] = false;
        $GLOBALS['noble_schema_test_is_date'] = false;
        $GLOBALS['noble_schema_test_query_vars'] = [];
        $GLOBALS['noble_schema_test_queried_object'] = null;
        $GLOBALS['noble_schema_test_taxonomies'] = [
            'category' => (object) ['labels' => (object) ['name' => 'Categories']],
            'post_tag' => (object) ['labels' => (object) ['name' => 'Tags']],
        ];
        $GLOBALS['noble_schema_test_post_types'] = [
            'post' => (object) ['labels' => (object) ['name' => 'Posts'], 'public' => true, 'has_archive' => false],
            'event' => (object) ['labels' => (object) ['name' => 'Events'], 'public' => true, 'has_archive' => true],
        ];
        unset($GLOBALS['noble_schema_test_archive_authors'], $GLOBALS['noble_schema_test_archive_dates'], $GLOBALS['noble_schema_test_terms']);
    }

    public function test_available_archives_includes_tax_author_date(): void {
        $values = array_column(Archive_Context::available_archives(), 'value');
        $this->assertContains('home', $values);
        $this->assertContains('post_type:event', $values);
        $this->assertContains('taxonomy:category', $values);
        $this->assertContains('taxonomy:post_tag', $values);
        $this->assertContains('author', $values);
        $this->assertContains('date', $values);
        $this->assertSame($values, Archive_Context::all_targeting_keys());
    }

    public function test_valid_targeting_keys(): void {
        $this->assertTrue(Archive_Context::is_valid_targeting_key('home'));
        $this->assertTrue(Archive_Context::is_valid_targeting_key('post_type:event'));
        $this->assertTrue(Archive_Context::is_valid_targeting_key('taxonomy:category'));
        $this->assertTrue(Archive_Context::is_valid_targeting_key('author'));
        $this->assertTrue(Archive_Context::is_valid_targeting_key('date'));
        $this->assertFalse(Archive_Context::is_valid_targeting_key('taxonomy:category:guides'));
        $this->assertFalse(Archive_Context::is_valid_targeting_key('author:12'));
        $this->assertFalse(Archive_Context::is_valid_targeting_key('nope'));
    }

    public function test_current_category_identity(): void {
        $GLOBALS['noble_schema_test_is_category'] = true;
        $GLOBALS['noble_schema_test_queried_object'] = (object) [
            'taxonomy' => 'category',
            'slug'     => 'guides',
            'name'     => 'Guides',
        ];

        $this->assertSame('taxonomy:category', Archive_Context::current_key());
        $this->assertSame('taxonomy:category:guides', Archive_Context::current_identity());

        $ctx = Archive_Context::context_from_identity('taxonomy:category:guides');
        $this->assertSame('taxonomy:category', $ctx['archive_key']);
        $this->assertSame('taxonomy:category:guides', $ctx['archive_identity']);
    }

    public function test_expand_taxonomy_and_date_and_author(): void {
        $GLOBALS['noble_schema_test_terms'] = [
            'category' => [
                (object) ['slug' => 'guides', 'name' => 'Guides'],
                (object) ['slug' => 'news', 'name' => 'News'],
            ],
        ];
        $GLOBALS['noble_schema_test_archive_authors'] = [3, 9];
        $GLOBALS['noble_schema_test_archive_dates'] = [
            'date:2024',
            'date:2024/06',
            'date:2024/06/15',
        ];

        $this->assertSame(
            ['taxonomy:category:guides', 'taxonomy:category:news'],
            Archive_Context::expand_to_identities('taxonomy:category')
        );
        $this->assertSame(['author:3', 'author:9'], Archive_Context::expand_to_identities('author'));
        $this->assertSame(
            ['date:2024', 'date:2024/06', 'date:2024/06/15'],
            Archive_Context::expand_to_identities('date')
        );
        $this->assertSame(['home'], Archive_Context::expand_to_identities('home'));
    }

    public function test_coarse_key_from_identity(): void {
        $this->assertSame('taxonomy:category', Archive_Context::coarse_key_from_identity('taxonomy:category:guides'));
        $this->assertSame('author', Archive_Context::coarse_key_from_identity('author:12'));
        $this->assertSame('date', Archive_Context::coarse_key_from_identity('date:2024/06'));
        $this->assertSame('post_type:event', Archive_Context::coarse_key_from_identity('post_type:event'));
    }
}
