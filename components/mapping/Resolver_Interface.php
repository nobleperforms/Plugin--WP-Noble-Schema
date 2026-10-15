<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

interface Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed;
}
