<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Conflict\Detector;
use NobleSchema\Tools\Log;
use PHPUnit\Framework\TestCase;

class Conflict_Detector_Log_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [
            'ns_logging_enabled' => '1',
        ];
        $GLOBALS['noble_schema_test_logs'] = [];
        $GLOBALS['noble_schema_test_log_seq'] = 0;
    }

    public function test_conflict_warning_logged_only_once_until_cleared(): void {
        $conflicts = [
            [
                'plugin'       => 'Yoast SEO',
                'settings_url' => null,
                'message'      => 'Yoast SEO is active and may output schema JSON-LD by default.',
            ],
        ];

        Detector::maybe_log_conflicts($conflicts);
        Detector::maybe_log_conflicts($conflicts);

        $this->assertCount(1, $GLOBALS['noble_schema_test_logs']);
        $this->assertSame('warning', $GLOBALS['noble_schema_test_logs'][0]['level']);
        $this->assertSame('conflict_detected', $GLOBALS['noble_schema_test_logs'][0]['category']);

        // Conflict resolved → fingerprint cleared.
        Detector::maybe_log_conflicts([]);
        $this->assertSame([], get_option(Detector::OPT_LOGGED_KEYS, []));

        // Same conflict returns → logs again.
        Detector::maybe_log_conflicts($conflicts);
        $this->assertCount(2, $GLOBALS['noble_schema_test_logs']);
    }
}
