<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Targeting\Matcher;
use PHPUnit\Framework\TestCase;

class Matcher_Test extends TestCase {
    public function test_post_type_match(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'post_type', 'status' => 'active'];
        $rows = [(object) ['rule_type' => 'post_type', 'rule_key' => null, 'rule_value' => 'post', 'operator' => 'is', 'rule_group' => 0]];
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 1,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
    }

    public function test_post_type_or_match(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'post_type', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'post_type', 'rule_key' => null, 'rule_value' => 'post', 'operator' => 'is', 'rule_group' => 0],
            (object) ['rule_type' => 'post_type', 'rule_key' => null, 'rule_value' => 'page', 'operator' => 'is', 'rule_group' => 0],
        ];

        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 1,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 2,
            'post_type' => 'page',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 3,
            'post_type' => 'event',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
    }

    public function test_mixed_placement_or_match(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'mixed', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'post_type', 'rule_key' => null, 'rule_value' => 'post', 'operator' => 'is', 'rule_group' => 0],
            (object) ['rule_type' => 'page_template', 'rule_key' => null, 'rule_value' => 'templates/guide.php', 'operator' => 'is', 'rule_group' => 0],
        ];

        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 1,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 2,
            'post_type' => 'page',
            'page_template' => 'templates/guide.php',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 3,
            'post_type' => 'page',
            'page_template' => 'templates/other.php',
            'block_types' => [],
            'taxonomies' => [],
        ]));
    }

    public function test_filter_groups_or_between_and_within(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'post_type', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'post_type', 'rule_key' => null, 'rule_value' => 'post', 'operator' => 'is', 'rule_group' => 0],
            // Group 0: category term 10 AND has block
            (object) ['rule_type' => 'taxonomy', 'rule_key' => 'category', 'rule_value' => '10', 'operator' => 'is', 'rule_group' => 0],
            (object) ['rule_type' => 'has_block', 'rule_key' => 'filter', 'rule_value' => 'acf/faq', 'operator' => 'is', 'rule_group' => 0],
            // Group 1: category term 20
            (object) ['rule_type' => 'taxonomy', 'rule_key' => 'category', 'rule_value' => '20', 'operator' => 'is', 'rule_group' => 1],
        ];

        $GLOBALS['noble_schema_test_post_terms'] = [
            1 => ['category' => [10]],
            2 => ['category' => [20]],
            3 => ['category' => [10]],
        ];

        // Matches group 0
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 1,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => ['acf/faq'],
            'taxonomies' => [],
        ]));
        // Matches group 1
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 2,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        // Group 0 incomplete (missing block) and not group 1
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 3,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        unset($GLOBALS['noble_schema_test_post_terms']);
    }

    public function test_taxonomy_filter_and_has_block(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'post_type', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'post_type', 'rule_key' => null, 'rule_value' => 'post', 'operator' => 'is', 'rule_group' => 0],
            (object) ['rule_type' => 'taxonomy', 'rule_key' => 'category', 'rule_value' => '10', 'operator' => 'is', 'rule_group' => 0],
            (object) ['rule_type' => 'has_block', 'rule_key' => 'filter', 'rule_value' => 'acf/faq', 'operator' => 'is', 'rule_group' => 0],
        ];
        $GLOBALS['noble_schema_test_post_terms'] = [
            1 => ['category' => [10]],
        ];
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 1,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => ['acf/faq'],
            'taxonomies' => [],
        ]));
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 1,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        unset($GLOBALS['noble_schema_test_post_terms']);
    }

    public function test_null_key_has_block_is_filter_not_placement(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'mixed', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'post_type', 'rule_key' => null, 'rule_value' => 'post', 'operator' => 'is', 'rule_group' => 0],
            (object) ['rule_type' => 'has_block', 'rule_key' => null, 'rule_value' => 'acf/faq', 'operator' => 'is', 'rule_group' => 0],
        ];

        // Has block alone must not satisfy placement OR.
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 1,
            'post_type' => 'page',
            'page_template' => '',
            'block_types' => ['acf/faq'],
            'taxonomies' => [],
        ]));

        // Post type matches placement; null-key has_block still ANDs as a filter.
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 2,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => ['acf/faq'],
            'taxonomies' => [],
        ]));
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 3,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
    }

    public function test_archive_placement_match(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'archive', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'archive', 'rule_key' => null, 'rule_value' => 'post_type:tribe_events', 'operator' => 'is', 'rule_group' => 0],
        ];

        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'archive_key' => 'post_type:tribe_events',
        ]));
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'archive_key' => 'home',
        ]));
    }

    public function test_archive_taxonomy_author_date_coarse_match(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'archive', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'archive', 'rule_key' => null, 'rule_value' => 'taxonomy:category', 'operator' => 'is', 'rule_group' => 0],
        ];

        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'archive_key' => 'taxonomy:category',
            'archive_identity' => 'taxonomy:category:guides',
        ]));
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'archive_key' => 'taxonomy:post_tag',
            'archive_identity' => 'taxonomy:post_tag:news',
        ]));

        $author_rows = [
            (object) ['rule_type' => 'archive', 'rule_key' => null, 'rule_value' => 'author', 'operator' => 'is', 'rule_group' => 0],
        ];
        $this->assertTrue($matcher->matches($config, $author_rows, [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'archive_key' => 'author',
            'archive_identity' => 'author:12',
        ]));

        $date_rows = [
            (object) ['rule_type' => 'archive', 'rule_key' => null, 'rule_value' => 'date', 'operator' => 'is', 'rule_group' => 0],
        ];
        $this->assertTrue($matcher->matches($config, $date_rows, [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'archive_key' => 'date',
            'archive_identity' => 'date:2024/06/15',
        ]));

        $global = (object) ['placement' => 'global', 'status' => 'active'];
        $this->assertTrue($matcher->matches($global, [], [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'archive_key' => 'date',
            'archive_identity' => 'date:2024',
        ]));

        $sitewide = (object) ['placement' => 'sitewide', 'status' => 'active'];
        $this->assertTrue($matcher->matches($sitewide, [], [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
    }

    public function test_post_meta_filter_operators(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'post_type', 'status' => 'active'];
        $placement = (object) [
            'rule_type'  => 'post_type',
            'rule_key'   => null,
            'rule_value' => 'post',
            'operator'   => 'is',
            'rule_group' => 0,
        ];

        $GLOBALS['noble_schema_test_post_meta'] = [
            10 => ['featured' => ['1'], 'rating' => ['4.5'], 'blurb' => ['hello world']],
            11 => [],
        ];

        $is = [
            $placement,
            (object) [
                'rule_type'  => 'post_meta',
                'rule_key'   => 'featured',
                'rule_value' => '1',
                'operator'   => 'is',
                'rule_group' => 0,
            ],
        ];
        $this->assertTrue($matcher->matches($config, $is, [
            'post_id' => 10,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertFalse($matcher->matches($config, $is, [
            'post_id' => 11,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        $exists = [
            $placement,
            (object) [
                'rule_type'  => 'post_meta',
                'rule_key'   => 'featured',
                'rule_value' => '',
                'operator'   => 'exists',
                'rule_group' => 0,
            ],
        ];
        $this->assertTrue($matcher->matches($config, $exists, [
            'post_id' => 10,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        $contains = [
            $placement,
            (object) [
                'rule_type'  => 'post_meta',
                'rule_key'   => 'blurb',
                'rule_value' => 'world',
                'operator'   => 'contains',
                'rule_group' => 0,
            ],
        ];
        $this->assertTrue($matcher->matches($config, $contains, [
            'post_id' => 10,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        $gte = [
            $placement,
            (object) [
                'rule_type'  => 'post_meta',
                'rule_key'   => 'rating',
                'rule_value' => '4',
                'operator'   => 'gte',
                'rule_group' => 0,
            ],
        ];
        $this->assertTrue($matcher->matches($config, $gte, [
            'post_id' => 10,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        unset($GLOBALS['noble_schema_test_post_meta']);
    }

    public function test_language_filter_operators(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'global', 'status' => 'active'];

        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_post_language'] = [
            10 => 'en',
            11 => 'es',
        ];

        $is_en = [
            (object) [
                'rule_type'  => 'language',
                'rule_key'   => '',
                'rule_value' => 'en',
                'operator'   => 'is',
                'rule_group' => 0,
            ],
        ];
        $this->assertTrue($matcher->matches($config, $is_en, [
            'post_id' => 10,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertFalse($matcher->matches($config, $is_en, [
            'post_id' => 11,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        $not_en = [
            (object) [
                'rule_type'  => 'language',
                'rule_key'   => '',
                'rule_value' => 'en',
                'operator'   => 'is_not',
                'rule_group' => 0,
            ],
        ];
        $this->assertFalse($matcher->matches($config, $not_en, [
            'post_id' => 10,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertTrue($matcher->matches($config, $not_en, [
            'post_id' => 11,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        $GLOBALS['noble_schema_test_wpml_active'] = false;
        $this->assertTrue($matcher->matches($config, $is_en, [
            'post_id' => 11,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        unset(
            $GLOBALS['noble_schema_test_wpml_active'],
            $GLOBALS['noble_schema_test_wpml_post_language']
        );
    }

    public function test_post_id_placement_match(): void {
        $matcher = new Matcher();
        $config = (object) ['placement' => 'post_id', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'post_id', 'rule_key' => null, 'rule_value' => '42', 'operator' => 'is', 'rule_group' => 0],
            (object) ['rule_type' => 'post_id', 'rule_key' => null, 'rule_value' => '99', 'operator' => 'is', 'rule_group' => 0],
        ];

        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 42,
            'post_type' => 'page',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 99,
            'post_type' => 'post',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 7,
            'post_type' => 'page',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
    }

    public function test_specialty_page_backed_matches_resolved_post(): void {
        $GLOBALS['noble_schema_test_options'] = [
            'show_on_front' => 'page',
            'page_on_front' => 55,
        ];

        $matcher = new Matcher();
        $config = (object) ['placement' => 'specialty', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'specialty', 'rule_key' => null, 'rule_value' => 'front_page', 'operator' => 'is', 'rule_group' => 0],
        ];

        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 55,
            'post_type' => 'page',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
        $this->assertFalse($matcher->matches($config, $rows, [
            'post_id' => 99,
            'post_type' => 'page',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));

        // Reassignment without retargeting still matches via stable key.
        $GLOBALS['noble_schema_test_options']['page_on_front'] = 77;
        $this->assertTrue($matcher->matches($config, $rows, [
            'post_id' => 77,
            'post_type' => 'page',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
        ]));
    }

    public function test_specialty_virtual_and_global(): void {
        $matcher = new Matcher();
        $targeted = (object) ['placement' => 'specialty', 'status' => 'active'];
        $global = (object) ['placement' => 'global', 'status' => 'active'];
        $rows = [
            (object) ['rule_type' => 'specialty', 'rule_key' => null, 'rule_value' => '404', 'operator' => 'is', 'rule_group' => 0],
        ];

        $ctx404 = [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'specialty_key' => '404',
        ];
        $ctxSearch = [
            'post_id' => 0,
            'post_type' => '',
            'page_template' => '',
            'block_types' => [],
            'taxonomies' => [],
            'specialty_key' => 'search',
        ];

        $this->assertTrue($matcher->matches($targeted, $rows, $ctx404));
        $this->assertFalse($matcher->matches($targeted, $rows, $ctxSearch));
        $this->assertTrue($matcher->matches($global, [], $ctx404));
        $this->assertTrue($matcher->matches($global, [], $ctxSearch));
    }
}
