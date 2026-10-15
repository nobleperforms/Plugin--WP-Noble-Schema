<?php

declare(strict_types=1);

namespace NobleSchema\SchemaOrg;

/**
 * Fetches and caches a compact schema.org vocabulary index for Schema Storefront.
 */
class Vocabulary {
    public const SOURCE_URL = 'https://schema.org/version/latest/schemaorg-current-https.jsonld';
    public const TRANSIENT_KEY = 'ns_schema_org_vocab';
    public const TTL = 86400; // 24 hours

    /**
     * @return array{fetched_at:int,types:array<string,array<string,mixed>>,properties:array<string,array<string,mixed>>,by_domain:array<string,string[]>}|null
     */
    public function get_index(bool $fetch_if_missing = true): ?array {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached) && isset($cached['types'], $cached['properties'])) {
            return $this->normalize_index($cached);
        }

        if (!$fetch_if_missing) {
            return null;
        }

        return $this->refresh();
    }

    /**
     * Re-fetch vocabulary. Keeps the previous cache if the new fetch/parse fails.
     *
     * @return array{fetched_at:int,types:array<string,array<string,mixed>>,properties:array<string,array<string,mixed>>,by_domain:array<string,string[]>}|null
     */
    public function refresh(): ?array {
        $previous = get_transient(self::TRANSIENT_KEY);
        if (!is_array($previous)) {
            $previous = null;
        }

        try {
            $raw = $this->fetch_raw();
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Type response was not valid JSON.');
            }

            $graph = $decoded['@graph'] ?? null;
            if (!is_array($graph)) {
                throw new \RuntimeException('Type JSON-LD is missing @graph.');
            }

            $index = self::build_index_from_graph($graph);
            $index['fetched_at'] = time();
            set_transient(self::TRANSIENT_KEY, $index, self::TTL);

            return $index;
        } catch (\Throwable $e) {
            if (is_array($previous)) {
                set_transient(self::TRANSIENT_KEY, $previous, self::TTL);
                return $this->normalize_index($previous);
            }

            throw $e;
        }
    }

    /**
     * @return array<int, array{id:string,label:string}>
     */
    public function list_types(): array {
        $index = $this->get_index();
        if ($index === null) {
            return [];
        }

        $list = [];
        foreach ($index['types'] as $id => $type) {
            $list[] = [
                'id'    => (string) $id,
                'label' => (string) ($type['label'] ?? $id),
            ];
        }

        usort($list, static function (array $a, array $b): int {
            return strcasecmp($a['label'], $b['label']);
        });

        return $list;
    }

    /**
     * @return array{
     *   id:string,
     *   label:string,
     *   comment:string,
     *   parents:string[],
     *   ancestors:string[],
     *   properties:array<int, array{id:string,label:string,comment:string,ranges:string[],inherited:bool}>
     * }|null
     */
    public function get_type(string $type_id): ?array {
        $index = $this->get_index();
        if ($index === null) {
            return null;
        }

        $type_id = self::local_name($type_id);
        $type = $index['types'][$type_id] ?? null;
        if (!is_array($type)) {
            return null;
        }

        $ancestors = $this->ancestor_chain($type_id, $index['types']);
        $chain = array_merge([$type_id], $ancestors);
        $props = [];
        $seen = [];

        foreach ($chain as $domain) {
            foreach ($index['by_domain'][$domain] ?? [] as $prop_id) {
                if (isset($seen[$prop_id])) {
                    continue;
                }
                $seen[$prop_id] = true;
                $prop = $index['properties'][$prop_id] ?? null;
                if (!is_array($prop)) {
                    continue;
                }
                $props[] = [
                    'id'        => $prop_id,
                    'label'     => (string) ($prop['label'] ?? $prop_id),
                    'comment'   => (string) ($prop['comment'] ?? ''),
                    'ranges'    => array_values(array_map('strval', (array) ($prop['ranges'] ?? []))),
                    'inherited' => $domain !== $type_id,
                ];
            }
        }

        usort($props, static function (array $a, array $b): int {
            if ($a['inherited'] !== $b['inherited']) {
                return $a['inherited'] ? 1 : -1;
            }
            return strcasecmp($a['label'], $b['label']);
        });

        return [
            'id'         => $type_id,
            'label'      => (string) ($type['label'] ?? $type_id),
            'comment'    => (string) ($type['comment'] ?? ''),
            'parents'    => array_values(array_map('strval', (array) ($type['parents'] ?? []))),
            'ancestors'  => $ancestors,
            'properties' => $props,
        ];
    }

    public function status(): array {
        $index = $this->get_index(false);
        if ($index === null) {
            return [
                'loaded'     => false,
                'fetched_at' => 0,
                'type_count' => 0,
            ];
        }

        return [
            'loaded'     => true,
            'fetched_at' => (int) ($index['fetched_at'] ?? 0),
            'type_count' => count($index['types']),
        ];
    }

    /**
     * @param array<int, mixed> $graph
     * @return array{fetched_at:int,types:array<string,array<string,mixed>>,properties:array<string,array<string,mixed>>,by_domain:array<string,string[]>}
     */
    public static function build_index_from_graph(array $graph): array {
        $classes = [];
        $properties = [];

        foreach ($graph as $item) {
            if (!is_array($item) || empty($item['@id'])) {
                continue;
            }

            $types = self::as_list($item['@type'] ?? null);
            $id = self::local_name((string) $item['@id']);
            if ($id === '') {
                continue;
            }

            if (in_array('rdfs:Class', $types, true) || in_array('http://www.w3.org/2000/01/rdf-schema#Class', $types, true)) {
                $is_datatype = in_array('schema:DataType', $types, true)
                    || in_array('https://schema.org/DataType', $types, true);
                $classes[$id] = [
                    'label'      => self::text_value($item['rdfs:label'] ?? $item['http://www.w3.org/2000/01/rdf-schema#label'] ?? $id),
                    'comment'    => self::text_value($item['rdfs:comment'] ?? $item['http://www.w3.org/2000/01/rdf-schema#comment'] ?? ''),
                    'parents'    => self::id_list($item['rdfs:subClassOf'] ?? $item['http://www.w3.org/2000/01/rdf-schema#subClassOf'] ?? null),
                    'is_datatype'=> $is_datatype,
                ];
            }

            if (in_array('rdf:Property', $types, true) || in_array('http://www.w3.org/1999/02/22-rdf-syntax-ns#Property', $types, true)) {
                $properties[$id] = [
                    'label'   => self::text_value($item['rdfs:label'] ?? $id),
                    'comment' => self::text_value($item['rdfs:comment'] ?? ''),
                    'domains' => self::id_list($item['schema:domainIncludes'] ?? $item['https://schema.org/domainIncludes'] ?? null),
                    'ranges'  => self::id_list($item['schema:rangeIncludes'] ?? $item['https://schema.org/rangeIncludes'] ?? null),
                ];
            }
        }

        $under_thing = self::descendants_of('Thing', $classes);
        $types = [];
        foreach (array_keys($under_thing) as $id) {
            $class = $classes[$id] ?? null;
            if (!is_array($class) || !empty($class['is_datatype'])) {
                continue;
            }
            $types[$id] = [
                'label'   => $class['label'],
                'comment' => $class['comment'],
                'parents' => array_values(array_filter(
                    $class['parents'],
                    static fn (string $parent) => isset($under_thing[$parent]) || $parent === 'Thing'
                )),
            ];
        }

        // Ensure Thing itself is listed.
        if (isset($classes['Thing']) && empty($classes['Thing']['is_datatype'])) {
            $types['Thing'] = [
                'label'   => $classes['Thing']['label'],
                'comment' => $classes['Thing']['comment'],
                'parents' => [],
            ];
        }

        $by_domain = [];
        foreach ($properties as $prop_id => $prop) {
            foreach ($prop['domains'] as $domain) {
                if (!isset($types[$domain]) && $domain !== 'Thing') {
                    continue;
                }
                $by_domain[$domain][] = $prop_id;
            }
        }
        foreach ($by_domain as $domain => $ids) {
            $by_domain[$domain] = array_values(array_unique($ids));
        }

        return [
            'fetched_at' => 0,
            'types'      => $types,
            'properties' => $properties,
            'by_domain'  => $by_domain,
        ];
    }

    public static function local_name(string $id): string {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        if (str_contains($id, 'schema:')) {
            return substr($id, strrpos($id, 'schema:') + strlen('schema:'));
        }
        if (str_contains($id, '://schema.org/')) {
            return basename(parse_url($id, PHP_URL_PATH) ?: $id);
        }
        if (str_contains($id, '/')) {
            return basename($id);
        }
        if (str_contains($id, '#')) {
            return substr($id, strrpos($id, '#') + 1);
        }
        if (str_contains($id, ':')) {
            return substr($id, strrpos($id, ':') + 1);
        }

        return $id;
    }

    private function fetch_raw(): string {
        $response = wp_remote_get(self::SOURCE_URL, [
            'timeout' => 90,
            'headers' => [
                'Accept' => 'application/ld+json, application/json;q=0.9,*/*;q=0.8',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(sprintf('schema.org returned HTTP %d.', $code));
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            throw new \RuntimeException('schema.org returned an empty type body.');
        }

        return $body;
    }

    /**
     * @param array<string, array<string, mixed>> $classes
     * @return array<string, true>
     */
    private static function descendants_of(string $root, array $classes): array {
        $children = [];
        foreach ($classes as $id => $class) {
            foreach ($class['parents'] as $parent) {
                $children[$parent][] = $id;
            }
        }

        $out = [];
        $queue = [$root];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($children[$current] ?? [] as $child) {
                if (isset($out[$child])) {
                    continue;
                }
                $out[$child] = true;
                $queue[] = $child;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $types
     * @return string[]
     */
    private function ancestor_chain(string $type_id, array $types): array {
        $chain = [];
        $queue = array_values(array_map('strval', (array) ($types[$type_id]['parents'] ?? [])));
        $seen = [$type_id => true];

        while ($queue !== []) {
            $parent = array_shift($queue);
            if ($parent === '' || isset($seen[$parent])) {
                continue;
            }
            $seen[$parent] = true;
            $chain[] = $parent;
            foreach ((array) ($types[$parent]['parents'] ?? []) as $next) {
                $queue[] = (string) $next;
            }
        }

        return $chain;
    }

    /**
     * @param array<string, mixed> $index
     * @return array{fetched_at:int,types:array<string,array<string,mixed>>,properties:array<string,array<string,mixed>>,by_domain:array<string,string[]>}
     */
    private function normalize_index(array $index): array {
        return [
            'fetched_at' => (int) ($index['fetched_at'] ?? 0),
            'types'      => is_array($index['types'] ?? null) ? $index['types'] : [],
            'properties' => is_array($index['properties'] ?? null) ? $index['properties'] : [],
            'by_domain'  => is_array($index['by_domain'] ?? null) ? $index['by_domain'] : [],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function as_list(mixed $value): array {
        if ($value === null) {
            return [];
        }
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    /**
     * @return string[]
     */
    private static function id_list(mixed $value): array {
        $out = [];
        foreach (self::as_list($value) as $item) {
            if (is_array($item) && isset($item['@id'])) {
                $out[] = self::local_name((string) $item['@id']);
            } elseif (is_string($item)) {
                $out[] = self::local_name($item);
            }
        }

        return array_values(array_filter($out));
    }

    private static function text_value(mixed $value): string {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['@value'])) {
            return (string) $value['@value'];
        }
        foreach ($value as $item) {
            if (is_string($item)) {
                return $item;
            }
            if (is_array($item) && isset($item['@value'])) {
                return (string) $item['@value'];
            }
        }

        return '';
    }
}
