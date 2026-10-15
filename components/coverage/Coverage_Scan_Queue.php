<?php

declare(strict_types=1);

namespace NobleSchema\Coverage;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Snapshot_Repository;
use NobleSchema\Schema\Schema_Validator;
use NobleSchema\Tools\Log;

/**
 * Batched Site Viewer “Scan for issues” job.
 */
final class Coverage_Scan_Queue {
    public const OPT_JOB = 'ns_coverage_scan_job';
    public const OPT_RESULTS = 'ns_coverage_scan_results';
    public const BATCH_SIZE = 8;

    /**
     * @param list<int>|null $post_ids When null, load published public posts.
     * @return array<string, mixed>
     */
    public static function start(?array $post_ids = null): array {
        if ($post_ids === null) {
            $post_ids = self::published_post_ids();
        }
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));

        $job = [
            'status'    => 'running',
            'post_ids'  => $post_ids,
            'cursor'    => 0,
            'total'     => count($post_ids),
            'processed' => 0,
            'started_at'=> time(),
            'updated_at'=> time(),
        ];
        update_option(self::OPT_JOB, $job, false);
        update_option(self::OPT_RESULTS, [
            'completed_at' => null,
            'posts'        => [],
        ], false);

        return self::get_status();
    }

    /**
     * @return array<string, mixed>
     */
    public static function process_batch(int $limit = self::BATCH_SIZE): array {
        $job = self::get_job();
        if ($job === null || ($job['status'] ?? '') !== 'running') {
            return self::get_status();
        }

        $limit = max(1, min(50, $limit));
        $ids = array_values((array) ($job['post_ids'] ?? []));
        $cursor = (int) ($job['cursor'] ?? 0);
        $slice = array_slice($ids, $cursor, $limit);

        $results = self::get_results() ?? ['completed_at' => null, 'posts' => []];
        $posts = is_array($results['posts'] ?? null) ? $results['posts'] : [];

        global $wpdb;
        $can_scan = isset($wpdb) && is_object($wpdb);

        foreach ($slice as $post_id) {
            $post_id = (int) $post_id;
            if ($can_scan) {
                try {
                    $posts[$post_id] = self::scan_post($post_id, new Snapshot_Repository(), new Config_Repository());
                } catch (\Throwable $e) {
                    $posts[$post_id] = self::empty_stub('scan_failed');
                }
            } else {
                $posts[$post_id] = self::empty_stub();
            }
            $cursor++;
            $job['processed'] = (int) ($job['processed'] ?? 0) + 1;
        }

        $job['cursor'] = $cursor;
        $job['updated_at'] = time();

        if ($cursor >= count($ids)) {
            $job['status'] = 'completed';
            $results['completed_at'] = time();
        }

        $results['posts'] = $posts;
        update_option(self::OPT_JOB, $job, false);
        update_option(self::OPT_RESULTS, $results, false);

        return self::get_status();
    }

    public static function cancel(): void {
        $job = self::get_job();
        if ($job === null) {
            return;
        }
        $job['status'] = 'cancelled';
        $job['updated_at'] = time();
        update_option(self::OPT_JOB, $job, false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_status(): array {
        $job = self::get_job();
        if ($job === null) {
            return [
                'status'     => 'idle',
                'total'      => 0,
                'processed'  => 0,
                'percent'    => 0,
                'done'       => true,
                'cancelled'  => false,
                'completed_at' => null,
                'completed_at_display' => '',
            ];
        }

        $total = max(0, (int) ($job['total'] ?? 0));
        $processed = max(0, (int) ($job['processed'] ?? 0));
        $status = (string) ($job['status'] ?? 'idle');
        $results = self::get_results();
        $completed_at = is_array($results) ? ($results['completed_at'] ?? null) : null;

        return [
            'status'       => $status,
            'total'        => $total,
            'processed'    => $processed,
            'percent'      => $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
            'done'         => in_array($status, ['completed', 'cancelled', 'idle'], true),
            'cancelled'    => $status === 'cancelled',
            'completed_at' => $completed_at,
            'completed_at_display' => $completed_at !== null ? self::format_timestamp((int) $completed_at) : '',
        ];
    }

    /**
     * Matches the system log timestamp format, e.g. "January 1, 2026 1:00pm PST".
     */
    private static function format_timestamp(int $timestamp): string {
        $format = 'F j, Y g:ia T';
        if (function_exists('wp_date')) {
            return (string) wp_date($format, $timestamp);
        }
        if (function_exists('date_i18n')) {
            return (string) date_i18n($format, $timestamp);
        }

        return gmdate($format, $timestamp);
    }

    /**
     * @return array{completed_at: int|null, posts: array<int|string, array<string, mixed>>}|null
     */
    public static function get_results(): ?array {
        $raw = get_option(self::OPT_RESULTS, null);
        if (!is_array($raw)) {
            return null;
        }

        return [
            'completed_at' => isset($raw['completed_at']) ? (is_numeric($raw['completed_at']) ? (int) $raw['completed_at'] : null) : null,
            'posts'        => is_array($raw['posts'] ?? null) ? $raw['posts'] : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function get_job(): ?array {
        $job = get_option(self::OPT_JOB, null);

        return is_array($job) ? $job : null;
    }

    /**
     * @return list<int>
     */
    private static function published_post_ids(): array {
        $post_types = get_post_types(['public' => true], 'names');
        if (!is_array($post_types) || $post_types === []) {
            $post_types = ['post', 'page'];
        }

        $query = new \WP_Query([
            'post_type'              => array_values($post_types),
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        return array_map('intval', (array) $query->posts);
    }

    /**
     * @return array{invalid: bool, issue_summary: string, reasons: list<string>, promote_incomplete: bool, live_complete: bool}
     */
    private static function empty_stub(string $reason = ''): array {
        $reasons = $reason !== '' ? [$reason] : [];

        return [
            'invalid'            => $reason !== '',
            'issue_summary'      => $reason !== '' ? $reason : '',
            'reasons'            => $reasons,
            'promote_incomplete' => false,
            'live_complete'      => false,
        ];
    }

    /**
     * @return array{invalid: bool, issue_summary: string, reasons: list<string>, promote_incomplete: bool, live_complete: bool}
     */
    private static function scan_post(int $post_id, Snapshot_Repository $snapshots, Config_Repository $configs): array {
        $reasons = [];
        $invalid = false;
        $promote_incomplete = false;

        $row = $snapshots->get($post_id);
        $stored_json = $row ? (string) ($row->graph_json ?? '') : '';
        $stored_complete = $row ? !empty($row->is_complete) : false;
        $config_ids = Log::config_ids_from_snapshot_row($row);
        $placements = $configs->placements_for_ids($config_ids);

        $stored_class = Coverage_Classifier::classify([
            'graph_json'        => $stored_json !== '' ? $stored_json : null,
            'is_complete'       => $stored_complete,
            'config_ids'        => $config_ids,
            'config_placements' => $placements,
        ]);
        if (!empty($stored_class['invalid'])) {
            $invalid = true;
            $reasons[] = 'validation_error';
        }

        $live_json = null;
        $live_complete = false;
        $matched = false;

        try {
            if (function_exists('\\NobleSchema\\plugin')) {
                $preview = \NobleSchema\plugin()->generator()->preview_for_post($post_id);
                $live_json = is_string($preview['graph_json'] ?? null) ? $preview['graph_json'] : null;
                $live_complete = !empty($preview['is_complete']);
                $matched = !empty($preview['config_ids']);
            }
        } catch (\Throwable $e) {
            $reasons[] = 'live_failed';
            $invalid = true;
        }

        try {
            if (function_exists('\\NobleSchema\\plugin')) {
                $diag = \NobleSchema\plugin()->generator()->diagnose_for_post($post_id);
                if (is_array($diag)) {
                    $matched = $matched || !empty($diag['matched_configs']);
                    foreach ((array) ($diag['configs'] ?? []) as $cfg) {
                        if (!empty($cfg['missing_required'])) {
                            $reasons[] = 'missing_required';
                            $invalid = true;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // diagnose optional
        }

        if ($matched && ($stored_json === '' || !$stored_complete)) {
            $reasons[] = 'match_no_snapshot';
            $promote_incomplete = true;
        }

        if ($live_json !== null && $stored_json !== '' && hash('sha256', $live_json) !== hash('sha256', $stored_json)) {
            $reasons[] = 'live_mismatch';
        }

        if ($live_json !== null) {
            $live_validation = Schema_Validator::validate($live_json);
            if ((int) ($live_validation['error_count'] ?? 0) > 0) {
                $invalid = true;
                if (!in_array('validation_error', $reasons, true)) {
                    $reasons[] = 'validation_error';
                }
            }
        }

        $labels = self::reason_labels();
        $summary_parts = [];
        foreach (array_values(array_unique($reasons)) as $reason) {
            $summary_parts[] = $labels[$reason] ?? $reason;
        }

        return [
            'invalid'            => $invalid || $reasons !== [],
            'issue_summary'      => implode('; ', $summary_parts),
            'reasons'            => array_values(array_unique($reasons)),
            'promote_incomplete' => $promote_incomplete,
            'live_complete'      => $live_complete,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function reason_labels(): array {
        return [
            'no_schema'         => __('No schema', 'noble-schema'),
            'validation_error'  => __('Validation errors', 'noble-schema'),
            'missing_required'  => __('Missing required fields', 'noble-schema'),
            'match_no_snapshot' => __('Configs match but no complete snapshot', 'noble-schema'),
            'live_mismatch'     => __('Live preview differs from stored', 'noble-schema'),
            'live_failed'       => __('Live generate failed', 'noble-schema'),
            'scan_failed'       => __('Scan failed', 'noble-schema'),
        ];
    }
}
