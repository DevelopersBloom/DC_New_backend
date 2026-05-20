<?php

namespace Tests\Unit;

use App\Services\DealsTableOnlyUpdateService;
use PHPUnit\Framework\TestCase;

class DealsTableOnlyUpdateServiceTest extends TestCase
{
    public function test_allowed_columns_are_deals_table_fields_only(): void
    {
        $this->assertSame(
            ['amount', 'interest_amount', 'penalty', 'cash', 'date'],
            DealsTableOnlyUpdateService::ALLOWED_COLUMNS
        );
    }
}
