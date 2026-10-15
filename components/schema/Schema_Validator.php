<?php

declare(strict_types=1);

namespace NobleSchema\Schema;

/**
 * Structural JSON-LD checks for Noble Schema graph documents.
 */
class Schema_Validator {
    /**
     * @return array{
     *   ok: bool,
     *   error_count: int,
     *   warning_count: int,
     *   issues: list<array{severity: string, code: string, message: string, path?: string}>
     * }
     */
    /**
     * Validate the snapshot array carried by Schema_Inspector reports.
     *
     * @param array<string, mixed>|null $snapshot
     * @return array<string, mixed>
     */
    public static function validate_snapshot(?array $snapshot): array {
        if (!is_array($snapshot) || empty($snapshot['present'])) {
            return self::validate(null);
        }

        $graph_json = $snapshot['graph_json'] ?? null;

        return self::validate(is_string($graph_json) ? $graph_json : null);
    }

    public static function validate(?string $graph_json): array {
        $issues = [];

        if ($graph_json === null || trim($graph_json) === '') {
            $issues[] = [
                'severity' => 'error',
                'code'     => 'empty',
                'message'  => 'No schema JSON is available for this post.',
            ];
            return self::result($issues);
        }

        $data = json_decode($graph_json, true);
        if (!is_array($data)) {
            $issues[] = [
                'severity' => 'error',
                'code'     => 'invalid_json',
                'message'  => 'Schema is not valid JSON.',
            ];
            return self::result($issues);
        }

        if (!array_key_exists('@context', $data) || $data['@context'] === null || $data['@context'] === '') {
            $issues[] = [
                'severity' => 'error',
                'code'     => 'missing_context',
                'message'  => 'Missing @context.',
                'path'     => '@context',
            ];
        }

        if (!array_key_exists('@graph', $data)) {
            $issues[] = [
                'severity' => 'error',
                'code'     => 'missing_graph',
                'message'  => 'Missing @graph.',
                'path'     => '@graph',
            ];
            return self::result($issues);
        }

        if (!is_array($data['@graph'])) {
            $issues[] = [
                'severity' => 'error',
                'code'     => 'graph_not_array',
                'message'  => '@graph must be an array.',
                'path'     => '@graph',
            ];
            return self::result($issues);
        }

        // Distinguish list vs associative object masquerading as array.
        if ($data['@graph'] !== [] && !array_is_list($data['@graph'])) {
            $issues[] = [
                'severity' => 'error',
                'code'     => 'graph_not_list',
                'message'  => '@graph must be a JSON array of nodes.',
                'path'     => '@graph',
            ];
            return self::result($issues);
        }

        if ($data['@graph'] === []) {
            $issues[] = [
                'severity' => 'error',
                'code'     => 'empty_graph',
                'message'  => '@graph is empty.',
                'path'     => '@graph',
            ];
            return self::result($issues);
        }

        foreach ($data['@graph'] as $index => $node) {
            $path = '@graph[' . $index . ']';
            if (!is_array($node)) {
                $issues[] = [
                    'severity' => 'error',
                    'code'     => 'node_not_object',
                    'message'  => 'Graph node must be an object.',
                    'path'     => $path,
                ];
                continue;
            }

            if (!array_key_exists('@type', $node) || $node['@type'] === null || $node['@type'] === '') {
                $issues[] = [
                    'severity' => 'error',
                    'code'     => 'missing_type',
                    'message'  => 'Graph node is missing @type.',
                    'path'     => $path . '.@type',
                ];
            }

            if (!array_key_exists('@id', $node) || $node['@id'] === null || $node['@id'] === '') {
                $issues[] = [
                    'severity' => 'warning',
                    'code'     => 'missing_id',
                    'message'  => 'Graph node is missing @id.',
                    'path'     => $path . '.@id',
                ];
            }
        }

        return self::result($issues);
    }

    /**
     * @param list<array{severity: string, code: string, message: string, path?: string}> $issues
     * @return array{
     *   ok: bool,
     *   error_count: int,
     *   warning_count: int,
     *   issues: list<array{severity: string, code: string, message: string, path?: string}>
     * }
     */
    private static function result(array $issues): array {
        $errors = 0;
        $warnings = 0;
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'warning') {
                $warnings++;
            } else {
                $errors++;
            }
        }

        return [
            'ok'            => $errors === 0,
            'error_count'   => $errors,
            'warning_count' => $warnings,
            'issues'        => $issues,
        ];
    }
}
