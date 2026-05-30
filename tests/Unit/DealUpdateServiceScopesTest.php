<?php

namespace Tests\Unit;

use App\Services\DealUpdateService;
use PHPUnit\Framework\TestCase;

class DealUpdateServiceScopesTest extends TestCase
{
    public function test_has_any_scope_returns_false_for_empty(): void
    {
        $service = new DealUpdateService();
        $this->assertFalse($service->hasAnyScope([]));
    }

    public function test_has_any_scope_returns_true_when_scope_set(): void
    {
        $service = new DealUpdateService();
        $this->assertTrue($service->hasAnyScope(['contract' => true]));
        $this->assertFalse($service->hasAnyScope(['contract' => false]));
    }
}
