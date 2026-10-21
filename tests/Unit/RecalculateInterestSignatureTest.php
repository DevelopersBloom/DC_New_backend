<?php

namespace Tests\Unit;

use App\Services\Payments\ScheduledPaymentHandler;
use PHPUnit\Framework\TestCase;

/**
 * T6: recalculateSchedule() calls recalculateInterest() with three arguments, so
 * $timing (and everything before it) must be optional. A required parameter after
 * optional ones silently turns the optional ones into required ones in PHP 8.
 */
class RecalculateInterestSignatureTest extends TestCase
{
    public function test_only_contract_and_payments_are_required(): void
    {
        $method = new \ReflectionMethod(ScheduledPaymentHandler::class, 'recalculateInterest');

        $this->assertSame(2, $method->getNumberOfRequiredParameters());

        $timing = $method->getParameters()[4];
        $this->assertSame('timing', $timing->getName());
        $this->assertTrue($timing->isDefaultValueAvailable());
        $this->assertNull($timing->getDefaultValue());
    }
}
