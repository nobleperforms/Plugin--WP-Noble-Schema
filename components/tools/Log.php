<?php

declare(strict_types=1);

namespace NobleSchema\Tools;

/**
 * Structured system logger: DB when enabled; PHP error_log for errors.
 */
class Log {
    public const OPT_ENABLED = 'ns_logging_enabled';
    public const OPT_RETENTION = 'ns_logging_retention';
    public const CRON_HOOK = 'ns_schema_logs_cleanup';
    public const DEFAULT_RETENTION = '3m';

    /**
     * @return array<string, string> slug => label
     */
    public static function retention_choices(): array {
        return [
            '1h' => __('1 hour', 'noble-schema'),
            '1d' => __('1 day', 'noble-schema'),
            '1w' => __('1 week', 'noble-schema'),
            '1m' => __('1 month', 'noble-schema'),
            '3m' => __('3 months', 'noble-schema'),
        ];
    }

    public static function enabled(): bool {
        return (string) get_option(self::OPT_ENABLED, '') === '1';
    }

    public static function retention_slug(): string {
        $slug = sanitize_key((string) get_option(self::OPT_RETENTION, self::DEFAULT_RETENTION));
        $choices = self::retention_choices();

        return isset($choices[$slug]) ? $slug : self::DEFAULT_RETENTION;
    }

    public static function sanitize_retention($value): string {
        $slug = sanitize_key((string) $value);
        $choices = self::retention_choices();

        return isset($choices[$slug]) ? $slug : self::DEFAULT_RETENTION;
    }

    public static function retention_seconds(?string $slug = null): int {
        $slug = $slug ?? self::retention_slug();
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $week = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
        $month = defined('MONTH_IN_SECONDS') ? MONTH_IN_SECONDS : 2592000;

        return match ($slug) {
            '1h' => $hour,
            '1d' => $day,
            '1w' => $week,
            '1m' => $month,
            '3m' => 3 * $month,
            default => 3 * $month,
        };
    }

    public static function info(string $message, array $context = []): void {
        self::write($message, $context, 'info');
    }

    public static function warning(string $message, array $context = []): void {
        self::write($message, $context, 'warning');
    }

    public static function error(string $message, array $context = []): void {
        self::write($message, $context, 'error');
    }

    /**
     * Insert a log row and return its ID (0 when logging is disabled or insert fails).
     */
    public static function insert(string $message, array $context = [], string $level = 'info'): int {
        return (int) (self::write($message, $context, $level) ?? 0);
    }

    /**
     * Update an existing log row (message + context). Returns false when disabled/missing.
     */
    public static function update(int $id, string $message, array $context = [], ?string $level = null): bool {
        $id = max(0, $id);
        if ($id <= 0 || !self::enabled()) {
            return false;
        }

        $category = sanitize_key((string) ($context['category'] ?? 'system'));
        if ($category === '') {
            $category = 'system';
        }
        $post_id = isset($context['post_id']) ? (int) $context['post_id'] : 0;
        $context_for_json = $context;
        unset($context_for_json['category'], $context_for_json['post_id']);
        $context_json = wp_json_encode($context_for_json) ?: '{}';

        if (isset($GLOBALS['noble_schema_test_logs']) && is_array($GLOBALS['noble_schema_test_logs'])) {
            foreach ($GLOBALS['noble_schema_test_logs'] as $index => $row) {
                if ((int) ($row['id'] ?? 0) !== $id) {
                    continue;
                }
                $GLOBALS['noble_schema_test_logs'][$index]['message'] = $message;
                $GLOBALS['noble_schema_test_logs'][$index]['category'] = $category;
                $GLOBALS['noble_schema_test_logs'][$index]['post_id'] = $post_id > 0 ? $post_id : null;
                $GLOBALS['noble_schema_test_logs'][$index]['context'] = $context_json;
                if ($level !== null && $level !== '') {
                    $GLOBALS['noble_schema_test_logs'][$index]['level'] = $level;
                }

                return true;
            }

            return false;
        }

        global $wpdb;
        $table = self::table_name();
        $data = [
            'message'  => $message,
            'category' => $category,
            'post_id'  => $post_id > 0 ? $post_id : null,
            'context'  => $context_json,
        ];
        $formats = ['%s', '%s', '%d', '%s'];
        if ($level !== null && $level !== '') {
            $data['level'] = $level;
            $formats[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);

        return $updated !== false;
    }

    /**
     * @param list<int> $before_config_ids
     * @param list<string> $before_types
     * @param list<int> $after_config_ids
     * @param list<string> $after_types
     */
    public static function schema_change(
        int $post_id,
        string $action,
        array $before_config_ids = [],
        array $before_types = [],
        array $after_config_ids = [],
        array $after_types = [],
        ?string $before_graph_json = null,
        ?string $after_graph_json = null
    ): void {
        if (!self::enabled()) {
            return;
        }

        $action = sanitize_key($action);
        $messages = [
            'created' => __('Schema snapshot created', 'noble-schema'),
            'updated' => __('Schema snapshot updated', 'noble-schema'),
            'cleared' => __('Schema snapshot cleared', 'noble-schema'),
        ];
        $message = $messages[$action] ?? __('Schema snapshot changed', 'noble-schema');
        $category = match ($action) {
            'created' => 'schema_created',
            'updated' => 'schema_updated',
            'cleared' => 'schema_cleared',
            default   => 'schema_changed',
        };

        self::info($message, [
            'category' => $category,
            'post_id'  => $post_id,
            'action'   => $action,
            'before'   => [
                'config_ids' => array_values(array_map('intval', $before_config_ids)),
                'types'      => array_values($before_types),
                'graph'      => self::decode_graph_for_log($before_graph_json),
            ],
            'after'    => [
                'config_ids' => array_values(array_map('intval', $after_config_ids)),
                'types'      => array_values($after_types),
                'graph'      => self::decode_graph_for_log($after_graph_json),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode_graph_for_log(?string $graph_json): ?array {
        if ($graph_json === null || $graph_json === '') {
            return null;
        }

        $decoded = json_decode($graph_json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    public static function types_from_graph_json(?string $graph_json): array {
        if ($graph_json === null || $graph_json === '') {
            return [];
        }

        $decoded = json_decode($graph_json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $graph = $decoded['@graph'] ?? null;
        $nodes = is_array($graph) ? $graph : [$decoded];
        $types = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['@type'] ?? null;
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            } elseif (is_array($type)) {
                foreach ($type as $t) {
                    if (is_string($t) && $t !== '') {
                        $types[] = $t;
                    }
                }
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @return list<int>
     */
    public static function config_ids_from_snapshot_row(?object $row): array {
        if ($row === null || !isset($row->config_ids)) {
            return [];
        }
        $decoded = json_decode((string) $row->config_ids, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', $decoded));
    }

    public static function purge_all(): int {
        if (isset($GLOBALS['noble_schema_test_logs']) && is_array($GLOBALS['noble_schema_test_logs'])) {
            $count = count($GLOBALS['noble_schema_test_logs']);
            $GLOBALS['noble_schema_test_logs'] = [];

            return $count;
        }

        global $wpdb;
        $table = self::table_name();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
        $wpdb->query("TRUNCATE TABLE {$table}");

        return $count;
    }

    /** Whether any log rows exist (ignores filters). */
    public static function has_any(): bool {
        if (isset($GLOBALS['noble_schema_test_logs']) && is_array($GLOBALS['noble_schema_test_logs'])) {
            return $GLOBALS['noble_schema_test_logs'] !== [];
        }

        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
        return (int) $wpdb->get_var("SELECT 1 FROM {$table} LIMIT 1") === 1;
    }

    public static function cleanup_expired(): int {
        if (!self::enabled()) {
            return 0;
        }

        $seconds = self::retention_seconds();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $seconds);

        if (isset($GLOBALS['noble_schema_test_logs']) && is_array($GLOBALS['noble_schema_test_logs'])) {
            $before = count($GLOBALS['noble_schema_test_logs']);
            $GLOBALS['noble_schema_test_logs'] = array_values(array_filter(
                $GLOBALS['noble_schema_test_logs'],
                static fn ($row) => ($row['created_at'] ?? '') >= $cutoff
            ));

            return $before - count($GLOBALS['noble_schema_test_logs']);
        }

        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff)
        );
    }

    public static function schedule_cleanup(): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        wp_schedule_event(time() + $hour, 'hourly', self::CRON_HOOK);
    }

    public static function unschedule_cleanup(): void {
        if (!function_exists('wp_clear_scheduled_hook')) {
            return;
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * @param array{
     *   level?:string,
     *   category?:string,
     *   post_id?:int,
     *   after_id?:int,
     *   before_id?:int,
     *   date_from?:string,
     *   date_to?:string,
     *   limit?:int
     * } $args
     * @return list<array<string, mixed>>
     */
    public static function query(array $args = []): array {
        $level = isset($args['level']) ? sanitize_key((string) $args['level']) : '';
        $category = isset($args['category']) ? sanitize_key((string) $args['category']) : '';
        $post_id = isset($args['post_id']) ? (int) $args['post_id'] : 0;
        $after_id = isset($args['after_id']) ? (int) $args['after_id'] : 0;
        $before_id = isset($args['before_id']) ? (int) $args['before_id'] : 0;
        $date_from = self::sanitize_date_ymd($args['date_from'] ?? '');
        $date_to = self::sanitize_date_ymd($args['date_to'] ?? '');
        $limit = isset($args['limit']) ? max(1, min(200, (int) $args['limit'])) : 50;

        if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
            [$date_from, $date_to] = [$date_to, $date_from];
        }
        $from_gmt = $date_from !== '' ? self::local_day_boundary_gmt($date_from, false) : '';
        $to_gmt = $date_to !== '' ? self::local_day_boundary_gmt($date_to, true) : '';

        if (isset($GLOBALS['noble_schema_test_logs']) && is_array($GLOBALS['noble_schema_test_logs'])) {
            $rows = $GLOBALS['noble_schema_test_logs'];
            usort($rows, static function ($a, $b): int {
                $a_pin = self::raw_row_is_running_regen($a) ? 0 : 1;
                $b_pin = self::raw_row_is_running_regen($b) ? 0 : 1;
                if ($a_pin !== $b_pin) {
                    return $a_pin <=> $b_pin;
                }

                return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
            });
            $out = [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($after_id > 0 && $id <= $after_id) {
                    continue;
                }
                if ($before_id > 0 && $id >= $before_id) {
                    continue;
                }
                if ($level !== '' && ($row['level'] ?? '') !== $level) {
                    continue;
                }
                if ($category !== '' && ($row['category'] ?? '') !== $category) {
                    continue;
                }
                if ($post_id > 0 && (int) ($row['post_id'] ?? 0) !== $post_id) {
                    continue;
                }
                $created = (string) ($row['created_at'] ?? '');
                if ($from_gmt !== '' && $created < $from_gmt) {
                    continue;
                }
                if ($to_gmt !== '' && $created > $to_gmt) {
                    continue;
                }
                $out[] = self::normalize_row($row);
                if (count($out) >= $limit) {
                    break;
                }
            }

            return $out;
        }

        global $wpdb;
        $table = self::table_name();
        $where = ['1=1'];
        $params = [];

        if ($after_id > 0) {
            $where[] = 'id > %d';
            $params[] = $after_id;
        }
        if ($before_id > 0) {
            $where[] = 'id < %d';
            $params[] = $before_id;
        }
        if ($level !== '') {
            $where[] = 'level = %s';
            $params[] = $level;
        }
        if ($category !== '') {
            $where[] = 'category = %s';
            $params[] = $category;
        }
        if ($post_id > 0) {
            $where[] = 'post_id = %d';
            $params[] = $post_id;
        }
        if ($from_gmt !== '') {
            $where[] = 'created_at >= %s';
            $params[] = $from_gmt;
        }
        if ($to_gmt !== '') {
            $where[] = 'created_at <= %s';
            $params[] = $to_gmt;
        }

        // Keep in-progress regen events above newer per-post noise during a sync.
        $order_by = 'ORDER BY CASE WHEN category = \'regen_started\' AND context LIKE \'%"status":"running"%\' THEN 0 ELSE 1 END ASC, id DESC';

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where)
            . ' ' . $order_by . ' LIMIT ' . $limit;

        if ($params !== []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare($sql, $params);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($results)) {
            return [];
        }

        return array_map([self::class, 'normalize_row'], $results);
    }

    /**
     * @param array<string, mixed> $row Raw DB/test row (context may be JSON string).
     */
    private static function raw_row_is_running_regen(array $row): bool {
        if (($row['category'] ?? '') !== 'regen_started') {
            return false;
        }

        $context_raw = $row['context'] ?? '{}';
        $context = is_string($context_raw) ? json_decode($context_raw, true) : $context_raw;
        if (!is_array($context)) {
            return false;
        }

        return ($context['status'] ?? '') === 'running';
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_date_ymd($value): string {
        $value = sanitize_text_field((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * Convert a site-local calendar day to a GMT MySQL datetime boundary.
     */
    public static function local_day_boundary_gmt(string $ymd, bool $end_of_day): string {
        $ymd = self::sanitize_date_ymd($ymd);
        if ($ymd === '') {
            return '';
        }

        $tz = function_exists('wp_timezone')
            ? wp_timezone()
            : new \DateTimeZone('UTC');

        try {
            $local = new \DateTimeImmutable(
                $ymd . ($end_of_day ? ' 23:59:59' : ' 00:00:00'),
                $tz
            );
        } catch (\Exception $e) {
            return '';
        }

        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Newest log rows tagged to a post (post_id column or context.post_ids).
     *
     * @return list<array<string, mixed>>
     */
    public static function for_post(int $post_id, int $limit = 20): array {
        $post_id = max(0, $post_id);
        $limit = max(1, min(100, $limit));
        if ($post_id <= 0) {
            return [];
        }

        if (isset($GLOBALS['noble_schema_test_logs']) && is_array($GLOBALS['noble_schema_test_logs'])) {
            $rows = $GLOBALS['noble_schema_test_logs'];
            usort($rows, static fn ($a, $b) => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)));
            $out = [];
            foreach ($rows as $row) {
                if (!self::row_mentions_post($row, $post_id)) {
                    continue;
                }
                $out[] = self::normalize_row($row);
                if (count($out) >= $limit) {
                    break;
                }
            }

            return $out;
        }

        global $wpdb;
        $table = self::table_name();
        $id = $post_id;
        // Compact JSON from wp_json_encode — match id at start/middle/end of post_ids array.
        $like_start = '%"post_ids":[' . $id . ',%';
        $like_only = '%"post_ids":[' . $id . ']%';
        $like_mid = '%"post_ids":[%,' . $id . ',%';
        $like_end = '%"post_ids":[%,' . $id . ']%';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
            WHERE post_id = %d
               OR context LIKE %s
               OR context LIKE %s
               OR context LIKE %s
               OR context LIKE %s
            ORDER BY id DESC
            LIMIT %d",
            $id,
            $like_start,
            $like_only,
            $like_mid,
            $like_end,
            $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $row) {
            if (!self::row_mentions_post($row, $post_id)) {
                continue;
            }
            $out[] = self::normalize_row($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row_mentions_post(array $row, int $post_id): bool {
        if ((int) ($row['post_id'] ?? 0) === $post_id) {
            return true;
        }

        $context_raw = $row['context'] ?? '{}';
        $context = is_string($context_raw) ? json_decode($context_raw, true) : $context_raw;
        if (!is_array($context)) {
            return false;
        }

        $ids = $context['post_ids'] ?? null;
        if (!is_array($ids)) {
            return false;
        }

        foreach ($ids as $candidate) {
            if ((int) $candidate === $post_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{levels:list<string>,categories:list<string>}
     */
    public static function filter_options(): array {
        if (isset($GLOBALS['noble_schema_test_logs']) && is_array($GLOBALS['noble_schema_test_logs'])) {
            $levels = [];
            $categories = [];
            foreach ($GLOBALS['noble_schema_test_logs'] as $row) {
                if (!empty($row['level'])) {
                    $levels[(string) $row['level']] = true;
                }
                if (!empty($row['category'])) {
                    $categories[(string) $row['category']] = true;
                }
            }

            return [
                'levels'     => array_keys($levels),
                'categories' => array_keys($categories),
            ];
        }

        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $levels = $wpdb->get_col("SELECT DISTINCT level FROM {$table} ORDER BY level ASC");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $categories = $wpdb->get_col("SELECT DISTINCT category FROM {$table} ORDER BY category ASC");

        return [
            'levels'     => is_array($levels) ? array_values(array_map('strval', $levels)) : [],
            'categories' => is_array($categories) ? array_values(array_map('strval', $categories)) : [],
        ];
    }

    public static function table_name(): string {
        global $wpdb;

        return $wpdb->prefix . 'ns_logs';
    }

    private static function write(string $message, array $context, string $level): ?int {
        $category = sanitize_key((string) ($context['category'] ?? 'system'));
        if ($category === '') {
            $category = 'system';
        }
        $post_id = isset($context['post_id']) ? (int) $context['post_id'] : 0;

        $context_for_json = $context;
        unset($context_for_json['category'], $context_for_json['post_id']);

        // Always mirror errors to PHP error_log (including when DB logging is off).
        if ($level === 'error') {
            $entry = '[NobleSchema][ERROR] ' . $message;
            if ($context_for_json !== []) {
                $entry .= ' ' . wp_json_encode($context_for_json);
            }
            error_log($entry);
        }

        if (!self::enabled()) {
            return null;
        }

        $created_at = function_exists('current_time')
            ? (string) current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');

        $row = [
            'created_at' => $created_at,
            'level'      => $level,
            'category'   => $category,
            'post_id'    => $post_id > 0 ? $post_id : null,
            'message'    => $message,
            'context'    => wp_json_encode($context_for_json) ?: '{}',
        ];

        if (isset($GLOBALS['noble_schema_test_logs']) && is_array($GLOBALS['noble_schema_test_logs'])) {
            $id = (int) ($GLOBALS['noble_schema_test_log_seq'] ?? 0) + 1;
            $GLOBALS['noble_schema_test_log_seq'] = $id;
            $row['id'] = $id;
            $GLOBALS['noble_schema_test_logs'][] = $row;

            return $id;
        }

        global $wpdb;
        $wpdb->insert(
            self::table_name(),
            [
                'created_at' => $row['created_at'],
                'level'      => $row['level'],
                'category'   => $row['category'],
                'post_id'    => $row['post_id'],
                'message'    => $row['message'],
                'context'    => $row['context'],
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        $id = (int) $wpdb->insert_id;

        if (function_exists('random_int') && random_int(1, 20) === 1) {
            self::cleanup_expired();
        }

        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize_row(array $row): array {
        $context_raw = $row['context'] ?? '{}';
        $context = is_string($context_raw) ? json_decode($context_raw, true) : $context_raw;
        if (!is_array($context)) {
            $context = [];
        }

        $post_id = isset($row['post_id']) && $row['post_id'] !== null && $row['post_id'] !== ''
            ? (int) $row['post_id']
            : 0;

        $post_title = '';
        $post_edit_url = '';
        if ($post_id > 0 && function_exists('get_the_title')) {
            $post_title = html_entity_decode(
                (string) get_the_title($post_id),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            if (function_exists('get_edit_post_link')) {
                $link = get_edit_post_link($post_id, 'raw');
                $post_edit_url = is_string($link) ? $link : '';
            }
        }

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'created_at'    => self::format_created_at_local((string) ($row['created_at'] ?? '')),
            'level'         => (string) ($row['level'] ?? 'info'),
            'category'      => (string) ($row['category'] ?? 'system'),
            'post_id'       => $post_id > 0 ? $post_id : null,
            'post_title'    => $post_title,
            'post_edit_url' => $post_edit_url,
            'message'       => (string) ($row['message'] ?? ''),
            'context'       => $context,
        ];
    }

    /**
     * Logs are stored as GMT MySQL datetimes; display in the site timezone.
     */
    private static function format_created_at_local(string $gmt_mysql): string {
        $gmt_mysql = trim($gmt_mysql);
        if ($gmt_mysql === '') {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($gmt_mysql, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return $gmt_mysql;
        }

        $timestamp = $dt->getTimestamp();
        // e.g. "January 1, 2026 1:00pm PST"
        $format = 'F j, Y g:ia T';
        if (function_exists('wp_date')) {
            return (string) wp_date($format, $timestamp);
        }
        if (function_exists('get_date_from_gmt')) {
            return (string) get_date_from_gmt($gmt_mysql, $format);
        }
        if (function_exists('date_i18n')) {
            return (string) date_i18n($format, $timestamp);
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format($format);
    }
}
