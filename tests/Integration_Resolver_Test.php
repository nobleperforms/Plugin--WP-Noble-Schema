<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Mapping\Events_Calendar_Resolver;
use NobleSchema\Mapping\Yoast_Resolver;
use PHPUnit\Framework\TestCase;

class Integration_Resolver_Test extends TestCase {
    public function test_yoast_resolver_returns_null_when_inactive_or_empty(): void {
        $resolver = new Yoast_Resolver();
        $this->assertNull($resolver->resolve(['field' => 'title'], ['post_id' => 1]));
        $this->assertNull($resolver->resolve(['field' => ''], ['post_id' => 1]));
        $this->assertNull($resolver->resolve(['field' => 'title'], ['post_id' => 0]));
    }

    public function test_events_calendar_resolver_returns_null_when_inactive_or_empty(): void {
        $resolver = new Events_Calendar_Resolver();
        $this->assertNull($resolver->resolve(['field' => 'start_date'], ['post_id' => 1]));
        $this->assertNull($resolver->resolve(['field' => ''], ['post_id' => 1]));
        $this->assertNull($resolver->resolve(['field' => 'start_date'], ['post_id' => 0]));
    }

    public function test_events_calendar_rejects_unknown_field_key(): void {
        $resolver = new Events_Calendar_Resolver();
        $this->assertNull($resolver->resolve(['field' => 'not_a_real_field'], ['post_id' => 99]));
    }
}
