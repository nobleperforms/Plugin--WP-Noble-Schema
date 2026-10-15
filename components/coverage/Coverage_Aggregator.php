<?php

declare(strict_types=1);

namespace NobleSchema\Coverage;

use NobleSchema\Cache\Archive_Cache;
use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Snapshot_Repository;
use NobleSchema\Targeting\Archive_Context;
use NobleSchema\Tools\Log;

/**
 * Fast Site Viewer summary from snapshots + archive cache.
 */
final class Coverage_Aggregator {
    private ?Snapshot_Repository $snapshots;
    private ?Config_Repository $configs;
    private ?Archive_Cache $archives;

    public function __construct(
        ?Snapshot_Repository $snapshots = null,
        ?Config_Repository $configs = null,
        ?Archive_Cache $archives = null
    ) {
        $this->snapshots = $snapshots;
        $this->configs = $configs;
        $this->archives = $archives;
    }

    private function snapshots(): Snapshot_Repository {
        return $this->snapshots ??= new Snapshot_Repository();
    }

    private function configs(): Config_Repository {
        return $this->configs ??= new Config_Repository();
    }

    private function archives(): Archive_Cache {
        return $this->archives ??= new Archive_Cache();
    }

    /**
     * @param list<array{
     *   post_id: int,
     *   title?: string,
     *   post_type?: string,
     *   graph_json?: string|null,
     *   is_complete?: bool,
     *   config_ids?: list<int>,
     *   config_placements?: array<int, string>,
     *   issue_summary?: string,
     *   scan_invalid?: bool,
     *   scan_reasons?: list<string>
     * }> $items
     * @param list<array{
     *   archive_key: string,
     *   label?: string,
     *   graph_json?: string|null,
     *   is_complete?: bool,
     *   config_ids?: list<int>,
     *   config_placements?: array<int, string>
     * }> $archive_items
     * @return array<string, mixed>
     */
    public function build_summary(array $items, array $archive_items = []): array {
        $tier_counts = [
            Coverage_Classifier::TIER_NONE            => 0,
            Coverage_Classifier::TIER_SITEWIDE_ONLY   => 0,
            Coverage_Classifier::TIER_PAGE_INCOMPLETE => 0,
            Coverage_Classifier::TIER_PAGE_COMPLETE   => 0,
            Coverage_Classifier::TIER_VALIDATED       => 0,
        ];
        $sitewide_posts = 0;
        $page_schema_posts = 0;
        $invalid_posts = 0;
        $post_types = [];
        $issue_reasons = [];
        $rows = [];

        foreach ($items as $item) {
            $post_id = (int) ($item['post_id'] ?? 0);
            $post_type = (string) ($item['post_type'] ?? 'post');
            $post_types[$post_type] = ($post_types[$post_type] ?? 0) + 1;

            $classified = Coverage_Classifier::classify([
                'graph_json'        => $item['graph_json'] ?? null,
                'is_complete'       => !empty($item['is_complete']),
                'config_ids'        => $item['config_ids'] ?? [],
                'config_placements' => $item['config_placements'] ?? [],
            ]);

            $tier = $classified['tier'];
            $tier_counts[$tier] = ($tier_counts[$tier] ?? 0) + 1;

            if ($classified['has_sitewide']) {
                $sitewide_posts++;
            }
            if (in_array($tier, [Coverage_Classifier::TIER_VALIDATED, Coverage_Classifier::TIER_PAGE_COMPLETE], true)) {
                $page_schema_posts++;
            }

            $invalid = !empty($classified['invalid']) || !empty($item['scan_invalid']);
            $reasons = array_values(array_filter(array_map('strval', (array) ($item['scan_reasons'] ?? []))));

            $graph = $item['graph_json'] ?? null;
            $has_schema = is_string($graph) && trim($graph) !== '' && ($classified['types'] ?? []) !== [];
            if (!$has_schema) {
                // Coverage gap: include in Issues triage under its own reason.
                // Drop inherited "validation_error" tags from empty graphs — those are gaps, not broken JSON.
                $invalid = true;
                $reasons = array_values(array_filter(
                    $reasons,
                    static fn (string $reason): bool => $reason !== 'validation_error'
                ));
                if (!in_array('no_schema', $reasons, true)) {
                    $reasons[] = 'no_schema';
                }
            } elseif ($invalid && $reasons === [] && !empty($classified['invalid'])) {
                // Present schema failed structural checks, but no scan reasons yet.
                $reasons = ['validation_error'];
            }

            if ($invalid) {
                $invalid_posts++;
            }

            foreach ($reasons as $reason) {
                $issue_reasons[$reason] = ($issue_reasons[$reason] ?? 0) + 1;
            }

            $issue = (string) ($item['issue_summary'] ?? '');
            if (!$has_schema) {
                // Prefer the no-schema label over leftover scan/validator copy.
                $issue = Coverage_Scan_Queue::reason_labels()['no_schema'] ?? 'No schema';
            } elseif ($issue === '' && $invalid && !empty($classified['validation']['issues'][0]['message'])) {
                $issue = (string) $classified['validation']['issues'][0]['message'];
            }

            $rows[] = [
                'post_id'       => $post_id,
                'title'         => self::decode_title((string) ($item['title'] ?? ('#' . $post_id))),
                'post_type'     => $post_type,
                'tier'          => $tier,
                'types'         => $classified['types'],
                'page_types'    => $classified['page_types'],
                'sitewide_types'=> $classified['sitewide_types'],
                'invalid'       => $invalid,
                'issue_summary' => $issue,
                'scan_reasons'  => $reasons,
                'viewer_url'    => add_query_arg(
                    ['page' => 'schema-tools', 'tool' => 'checker', 'post_id' => $post_id],
                    admin_url('tools.php')
                ),
            ];
        }

        $published_total = count($items);
        $coverage_percent = $published_total > 0
            ? round(($page_schema_posts / $published_total) * 100, 1)
            : 0.0;

        $archives = [];
        foreach ($archive_items as $archive) {
            $classified = Coverage_Classifier::classify([
                'graph_json'        => $archive['graph_json'] ?? null,
                'is_complete'       => array_key_exists('is_complete', $archive)
                    ? !empty($archive['is_complete'])
                    : ((string) ($archive['graph_json'] ?? '') !== ''),
                'config_ids'        => $archive['config_ids'] ?? [],
                'config_placements' => $archive['config_placements'] ?? [],
            ]);
            $archives[] = [
                'archive_key'    => (string) ($archive['archive_key'] ?? ''),
                'label'          => (string) ($archive['label'] ?? ($archive['archive_key'] ?? '')),
                'tier'           => $classified['tier'],
                'types'          => $classified['types'],
                'page_types'     => $classified['page_types'],
                'sitewide_types' => $classified['sitewide_types'],
                'has_sitewide'   => $classified['has_sitewide'],
                'has_page_specific' => $classified['has_page_specific'],
                'invalid'        => !empty($classified['invalid']),
            ];
        }

        ksort($issue_reasons);

        return [
            'published_total'   => $published_total,
            'page_schema_posts' => $page_schema_posts,
            'coverage_percent'  => $coverage_percent,
            'tier_counts'       => $tier_counts,
            'invalid_posts'     => $invalid_posts,
            'sitewide_callout'  => [
                'posts' => $sitewide_posts,
                'note'  => __('Sitewide scripts (configured sitewide types and global configs) are listed separately and do not inflate coverage.', 'noble-schema'),
            ],
            'post_types'        => $post_types,
            'issue_reasons'     => $issue_reasons,
            'issue_reason_labels' => Coverage_Scan_Queue::reason_labels(),
            'archives'          => $archives,
            'rows'              => $rows,
        ];
    }

    /**
     * @param array{page?: int, per_page?: int, tier?: string, post_type?: string, invalid_only?: bool, issue_reason?: string} $args
     * @return array<string, mixed>
     */
    public function summarize(array $args = []): array {
        $items = $this->load_post_items();
        $archive_items = $this->load_archive_items();
        $scan = Coverage_Scan_Queue::get_results();
        if (is_array($scan) && !empty($scan['posts']) && is_array($scan['posts'])) {
            foreach ($items as &$item) {
                $pid = (int) $item['post_id'];
                $stub = $scan['posts'][$pid] ?? $scan['posts'][(string) $pid] ?? null;
                if (!is_array($stub)) {
                    continue;
                }
                $item['issue_summary'] = (string) ($stub['issue_summary'] ?? '');
                $item['scan_invalid'] = !empty($stub['invalid']);
                $item['scan_reasons'] = array_values((array) ($stub['reasons'] ?? []));
                if (!empty($stub['promote_incomplete']) && empty($item['graph_json'])) {
                    // Keep as none in classifier; issue_summary carries match_no_snapshot.
                }
            }
            unset($item);
        }

        $summary = $this->build_summary($items, $archive_items);
        $summary['scan'] = Coverage_Scan_Queue::get_status();
        $summary['triage'] = $this->paginate_rows($summary['rows'], $args);

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{page?: int, per_page?: int, tier?: string, post_type?: string, invalid_only?: bool, issue_reason?: string} $args
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate_rows(array $rows, array $args = []): array {
        $tier = sanitize_key((string) ($args['tier'] ?? ''));
        $post_type = sanitize_key((string) ($args['post_type'] ?? ''));
        $invalid_only = !empty($args['invalid_only']);
        $issue_reason = sanitize_key((string) ($args['issue_reason'] ?? ''));
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(100, max(1, (int) ($args['per_page'] ?? 25)));

        $filtered = array_values(array_filter($rows, static function (array $row) use ($tier, $post_type, $invalid_only, $issue_reason): bool {
            if ($tier !== '' && ($row['tier'] ?? '') !== $tier) {
                return false;
            }
            if ($post_type !== '' && ($row['post_type'] ?? '') !== $post_type) {
                return false;
            }
            if ($invalid_only && empty($row['invalid'])) {
                return false;
            }
            if ($issue_reason !== '') {
                $reasons = array_map('strval', (array) ($row['scan_reasons'] ?? []));
                if (!in_array($issue_reason, $reasons, true)) {
                    return false;
                }
            }

            return true;
        }));

        $total = count($filtered);
        $offset = ($page - 1) * $per_page;

        return [
            'rows'     => array_slice($filtered, $offset, $per_page),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function load_post_items(): array {
        $post_types = get_post_types(['public' => true], 'names');
        if (!is_array($post_types) || $post_types === []) {
            $post_types = ['post', 'page'];
        }
        $post_types = array_values(array_map('strval', $post_types));

        $query = new \WP_Query([
            'post_type'              => $post_types,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $ids = array_map('intval', (array) $query->posts);
        if ($ids === []) {
            return [];
        }

        $snapshots = $this->snapshots()->get_many($ids);
        $all_config_ids = [];
        foreach ($snapshots as $row) {
            foreach (Log::config_ids_from_snapshot_row($row) as $cid) {
                $all_config_ids[] = $cid;
            }
        }
        $placements = $this->configs()->placements_for_ids($all_config_ids);

        $items = [];
        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            $row = $snapshots[$post_id] ?? null;
            $config_ids = Log::config_ids_from_snapshot_row($row);
            $item_placements = [];
            foreach ($config_ids as $cid) {
                if (isset($placements[$cid])) {
                    $item_placements[$cid] = $placements[$cid];
                }
            }

            $items[] = [
                'post_id'           => $post_id,
                'title'             => $post instanceof \WP_Post
                    ? self::decode_title((string) $post->post_title)
                    : ('#' . $post_id),
                'post_type'         => $post instanceof \WP_Post ? (string) $post->post_type : 'post',
                'graph_json'        => $row ? (string) ($row->graph_json ?? '') : null,
                'is_complete'       => $row ? !empty($row->is_complete) : false,
                'config_ids'        => $config_ids,
                'config_placements' => $item_placements,
            ];
        }

        return $items;
    }

    private static function decode_title(string $title): string {
        return html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function load_archive_items(): array {
        $available = Archive_Context::available_archives();
        $all_ids = [];
        $raw = [];
        foreach ($available as $opt) {
            $key = (string) ($opt['value'] ?? '');
            if ($key === '') {
                continue;
            }
            $entry = $this->archives()->get($key);
            $config_ids = $entry['config_ids'] ?? [];
            foreach ($config_ids as $cid) {
                $all_ids[] = (int) $cid;
            }
            $raw[] = [
                'archive_key' => $key,
                'label'       => (string) ($opt['label'] ?? $key),
                'entry'       => $entry,
                'config_ids'  => $config_ids,
            ];
        }
        $placements = $this->configs()->placements_for_ids($all_ids);

        $items = [];
        foreach ($raw as $row) {
            $entry = $row['entry'];
            $config_ids = array_values(array_map('intval', (array) $row['config_ids']));
            $item_placements = [];
            foreach ($config_ids as $cid) {
                if (isset($placements[$cid])) {
                    $item_placements[$cid] = $placements[$cid];
                }
            }
            $items[] = [
                'archive_key'       => $row['archive_key'],
                'label'             => $row['label'],
                'graph_json'        => $entry['graph_json'] ?? null,
                'is_complete'       => is_array($entry) && !empty($entry['graph_json']),
                'config_ids'        => $config_ids,
                'config_placements' => $item_placements,
            ];
        }

        return $items;
    }
}
