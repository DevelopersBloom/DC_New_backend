<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;

trait CreditRegistryCodeTrait
{
    private function buildCreditCode(Contract $contract): string
    {
        $bank = '66100';
        $date = Carbon::parse($contract->date)->format('Ymd');
        $seq  = str_pad((string) ($contract->id % 99999), 5, '0', STR_PAD_LEFT);
        $base = $bank . $date . $seq;

        $cs = $this->cbaChecksum($base);

        return sprintf('%s-%s-%s%d', $bank, $date, $seq, $cs);
    }

    private function cbaChecksum(string $digits): int
    {
        $len = strlen($digits);
        $sum = 0;

        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$len - 1 - $i];

            if ($i % 2 === 0) {
                $doubled = $d * 2;
                $sum += intdiv($doubled, 10) + ($doubled % 10);
            } else {
                $sum += $d;
            }
        }

        return (10 - ($sum % 10)) % 10;
    }
}
