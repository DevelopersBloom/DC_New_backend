<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Admin deal edits: updates the deals table only.
 * Uses the query builder (not Deal::save()) so model events do not touch orders, histories, journals, etc.
 */
class DealsTableOnlyUpdateService
{
    public const ALLOWED_COLUMNS = [
        'amount',
        'interest_amount',
        'penalty',
        'principal_amount',
        'cash',
        'date',
    ];

    public function updateMany(array $dealsData): void
    {
        DB::transaction(function () use ($dealsData) {
            foreach ($dealsData as $dealData) {
                $this->updateOne($dealData);
            }
        });
    }

    public function updateOne(array $dealData): void
    {
        $dealId = $dealData['id'] ?? null;
        $existing = DB::table('deals')->where('id', $dealId)->first();

        if (!$existing) {
            throw new \InvalidArgumentException('Deal not found: ' . ($dealId ?? 'unknown'));
        }

        $updates = ['updated_at' => now()];

        foreach (self::ALLOWED_COLUMNS as $column) {
            if (!array_key_exists($column, $dealData)) {
                continue;
            }

            if ($column === 'cash') {
                $updates['cash'] = (bool) $dealData['cash'];
                continue;
            }

            if ($column === 'date') {
                $updates['date'] = $dealData['date'];
                continue;
            }

            $updates[$column] = (float) $dealData[$column];
        }

        DB::table('deals')->where('id', $dealId)->update($updates);
    }
}
