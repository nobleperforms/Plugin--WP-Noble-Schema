<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

class Static_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        return $source_config['value'] ?? null;
    }
}
