<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Mapping\Callback_Resolver;
use NobleSchema\Mapping\Resolver_Registry;
use NobleSchema\Mapping\Static_Resolver;
use PHPUnit\Framework\TestCase;

class Resolver_Registry_Test extends TestCase {
    protected function tearDown(): void {
        remove_all_filters();
        parent::tearDown();
    }

    public function test_static_resolver_returns_value(): void {
        $resolver = new Static_Resolver();
        $this->assertSame('Travel Nevada', $resolver->resolve(['value' => 'Travel Nevada'], []));
        $this->assertSame(['a' => 1], $resolver->resolve(['value' => ['a' => 1]], []));
    }

    public function test_unknown_callback_returns_null(): void {
        $resolver = new Callback_Resolver();
        $this->assertNull($resolver->resolve(['slug' => 'not-registered'], ['post_id' => 1]));
    }

    public function test_callback_resolves_via_named_filter(): void {
        add_filter(
            'ns_schema_callback_primary_destination',
            static function (array $config, array $context) {
                return 'dest:' . (int) ($context['post_id'] ?? 0);
            },
            10,
            2
        );

        $resolver = new Callback_Resolver();
        $this->assertSame(
            'dest:42',
            $resolver->resolve(['slug' => 'primary_destination'], ['post_id' => 42])
        );
    }

    public function test_callback_slugs_are_discovered_from_filters(): void {
        add_filter('ns_schema_callback_alpha', static fn(array $config, array $context) => 'a', 10, 2);
        add_filter('ns_schema_callback_beta', static fn(array $config, array $context) => 'b', 10, 2);
        add_filter('unrelated_hook', static fn($v) => $v);

        $resolver = new Callback_Resolver();
        $this->assertSame(['alpha', 'beta'], $resolver->slugs());
    }

    public function test_registry_unknown_source_type_returns_null(): void {
        $registry = new Resolver_Registry();
        $this->assertNull($registry->resolve('unknown', [], ['post_id' => 1]));
    }

    public function test_registry_resolves_registered_static_source(): void {
        $registry = new Resolver_Registry();
        $registry->register('static', new Static_Resolver());
        $this->assertSame(
            'hello',
            $registry->resolve('static', ['value' => 'hello'], ['post_id' => 1])
        );
    }

    public function test_registry_callback_allow_list(): void {
        add_filter(
            'ns_schema_callback_custom',
            static function (array $config, array $context) {
                return 'ok';
            },
            10,
            2
        );

        $registry = new Resolver_Registry();
        $callback = new Callback_Resolver();
        $registry->register('callback', $callback);

        $this->assertSame(['custom'], $registry->callback_slugs());
        $this->assertSame(
            'ok',
            $registry->resolve('callback', ['slug' => 'custom'], ['post_id' => 1])
        );
        $this->assertNull(
            $registry->resolve('callback', ['slug' => 'not-registered'], ['post_id' => 1])
        );
    }
}
