<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Targeting\Taxonomy_Rule;
use PHPUnit\Framework\TestCase;

class Taxonomy_Rule_Test extends TestCase {
    protected function tearDown(): void {
        unset(
            $GLOBALS['noble_schema_test_wpml_active'],
            $GLOBALS['noble_schema_test_wpml_term_translations'],
            $GLOBALS['noble_schema_test_post_terms'],
            $GLOBALS['noble_schema_test_terms'],
            $GLOBALS['noble_schema_test_taxonomies']
        );
        parent::tearDown();
    }

    public function test_selectable_taxonomies_includes_private_admin_taxonomies(): void {
        $GLOBALS['noble_schema_test_taxonomies'] = [
            'category' => (object) [
                'public'  => true,
                'show_ui' => true,
                'labels'  => (object) ['singular_name' => 'Category'],
            ],
            'event_categories' => (object) [
                'public'  => false,
                'show_ui' => true,
                'labels'  => (object) ['singular_name' => 'Event Category'],
            ],
            'nav_menu' => (object) [
                'public'  => false,
                'show_ui' => false,
                'labels'  => (object) ['singular_name' => 'Navigation Menu'],
            ],
        ];

        $selectable = Taxonomy_Rule::selectable_taxonomies();

        $this->assertSame(['category', 'event_categories'], array_keys($selectable));
        unset($GLOBALS['noble_schema_test_taxonomies']);
    }

    public function test_normalize_rule_requires_taxonomy_and_term_id(): void {
        $this->assertNull(Taxonomy_Rule::normalize_rule(['taxonomy' => 'category', 'term_id' => 0]));
        $this->assertNull(Taxonomy_Rule::normalize_rule(['taxonomy' => '', 'term_id' => 12]));
        $this->assertSame(
            [
                'type'     => 'taxonomy',
                'taxonomy' => 'category',
                'term_id'  => 12,
                'operator' => 'is_not',
            ],
            Taxonomy_Rule::normalize_rule(['taxonomy' => 'category', 'term' => '12', 'operator' => 'is_not'])
        );
    }

    public function test_expanded_term_ids_includes_wpml_siblings(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_term_translations'] = [
            10 => [20, 30],
        ];

        $this->assertSame([10, 20, 30], Taxonomy_Rule::expanded_term_ids(10, 'category'));
    }

    public function test_expanded_term_ids_without_wpml_is_self_only(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = false;
        $this->assertSame([10], Taxonomy_Rule::expanded_term_ids(10, 'category'));
    }

    public function test_matches_post_uses_expanded_ids(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_term_translations'] = [
            10 => [20],
        ];
        $GLOBALS['noble_schema_test_post_terms'] = [
            5 => ['category' => [20]],
            6 => ['category' => [99]],
        ];

        $this->assertTrue(Taxonomy_Rule::matches_post(5, 'category', 10, 'is'));
        $this->assertFalse(Taxonomy_Rule::matches_post(6, 'category', 10, 'is'));
        $this->assertTrue(Taxonomy_Rule::matches_post(6, 'category', 10, 'is_not'));
    }

    public function test_resolve_imported_term_id_prefers_slug_then_name(): void {
        $GLOBALS['noble_schema_test_terms'] = [
            'category' => [
                (object) ['term_id' => 44, 'slug' => 'arts-culture', 'name' => 'Arts & Culture', 'taxonomy' => 'category'],
                (object) ['term_id' => 55, 'slug' => 'other', 'name' => 'Other', 'taxonomy' => 'category'],
            ],
        ];

        $this->assertSame(44, Taxonomy_Rule::resolve_imported_term_id([
            'rule_key'   => 'category',
            'rule_value' => '999',
            'term_slug'  => 'arts-culture',
            'term_name'  => 'Other',
        ]));
        $this->assertSame(55, Taxonomy_Rule::resolve_imported_term_id([
            'rule_key'   => 'category',
            'rule_value' => '999',
            'term_name'  => 'Other',
        ]));
        $this->assertSame(44, Taxonomy_Rule::resolve_imported_term_id([
            'rule_key'   => 'category',
            'rule_value' => '44',
        ]));
        $this->assertSame(0, Taxonomy_Rule::resolve_imported_term_id([
            'rule_key'   => 'category',
            'rule_value' => '999',
            'term_slug'  => 'missing',
        ]));
    }
}
