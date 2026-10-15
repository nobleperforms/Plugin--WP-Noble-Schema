<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

use NobleSchema\Tools\Log;

class Resolver_Registry {
    /** @var array<string, Resolver_Interface> */
    private array $resolvers = [];

    private ?Callback_Resolver $callback_resolver = null;

    public function register(string $source_type, Resolver_Interface $resolver): void {
        $this->resolvers[$source_type] = $resolver;

        if ($resolver instanceof Callback_Resolver) {
            $this->callback_resolver = $resolver;
        }
    }

    /**
     * @return string[]
     */
    public function callback_slugs(): array {
        if ($this->callback_resolver === null) {
            return [];
        }

        return $this->callback_resolver->slugs();
    }

    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(string $source_type, array $source_config, array $context): mixed {
        if (!isset($this->resolvers[$source_type])) {
            Log::error('Unknown field source type.', [
                'category'    => 'resolver_failed',
                'source_type' => $source_type,
                'post_id'     => $context['post_id'] ?? null,
            ]);

            return null;
        }

        return $this->resolvers[$source_type]->resolve($source_config, $context);
    }
}
