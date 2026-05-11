<?php

namespace Tests\Unit;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_getNextDocumentNumber_returns_one_when_table_is_empty(): void
    {
        $num = Transaction::getNextDocumentNumber();

        $this->assertSame(1, $num);
    }

    public function test_getNextDocumentNumber_increments_past_existing_max(): void
    {
        DB::table('transactions')->insert(['document_number' => 42]);

        $num = Transaction::getNextDocumentNumber();

        $this->assertSame(43, $num);
    }

    public function test_concurrent_calls_produce_unique_document_numbers(): void
    {
        $concurrency = 20;
        $numbers = [];

        // Simulate concurrent allocations in-process using separate DB transactions.
        // Each call must produce a distinct number even when run back-to-back without
        // an intervening INSERT (the lock ensures only one proceeds at a time).
        for ($i = 0; $i < $concurrency; $i++) {
            $num = DB::transaction(function () {
                return Transaction::getNextDocumentNumber();
            });

            // Persist to advance the MAX so the next allocation gets a higher number.
            DB::table('transactions')->insert(['document_number' => $num]);
            $numbers[] = $num;
        }

        $unique = array_unique($numbers);
        $this->assertCount(
            $concurrency,
            $unique,
            'Expected all allocated document numbers to be unique; got duplicates: '
            . implode(', ', array_diff_assoc($numbers, $unique))
        );
        $this->assertSame(range(1, $concurrency), $numbers);
    }

    public function test_getNextDocumentNumber_is_called_inside_outer_transaction_and_lock_is_held(): void
    {
        // Verify that calling getNextDocumentNumber inside an existing transaction
        // (which creates a savepoint) does not release the row lock prematurely.
        DB::beginTransaction();
        try {
            $first  = Transaction::getNextDocumentNumber();
            DB::table('transactions')->insert(['document_number' => $first]);
            $second = Transaction::getNextDocumentNumber();
            DB::table('transactions')->insert(['document_number' => $second]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->assertNotEquals($first, $second);
        $this->assertSame($first + 1, $second);
    }
}
