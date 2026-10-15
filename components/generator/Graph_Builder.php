<?php

declare(strict_types=1);

namespace NobleSchema\Generator;

class Graph_Builder {
    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array{'@context': string, '@graph': array<int, array<string, mixed>>}
     */
    public function build(array $nodes): array {
        return [
            '@context' => 'https://schema.org',
            '@graph' => $nodes,
        ];
    }
}
