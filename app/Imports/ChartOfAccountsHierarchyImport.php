<?php
namespace App\Imports;

use App\Models\ChartOfAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class ChartOfAccountsHierarchyImport implements ToCollection
{
    protected int $maxCols;

    public function __construct(int $maxCols = 30)
    {
        $this->maxCols = $maxCols;
    }

    public function collection(Collection $rows)
    {
        $levelLast = [];
        $currentDescription = null;
        $currentType = null;

        foreach ($rows as $row) {
            $raw = $row->toArray();

            $codeCol = -1;
            for ($i = 0; $i < $this->maxCols; $i++) {
                $val = isset($raw[$i]) ? trim((string)$raw[$i]) : '';
                if ($val !== '') { $codeCol = $i; break; }
            }
            if ($codeCol === -1) continue;

            $nameCol = $codeCol + 1;

            $rawCode = isset($raw[$codeCol]) ? trim((string)$raw[$codeCol]) : '';
            $rawName = isset($raw[$nameCol]) ? trim((string)$raw[$nameCol]) : '';

            $isNumericCode = $this->looksLikeCode($rawCode);

            if ($codeCol === 0 && !$isNumericCode) {
                $currentDescription = $rawCode;
                $currentType = $this->inferTypeFromHeader($currentDescription, $rawName);
                continue;
            }

            $code = $isNumericCode ? $rawCode : '';
            $name = $rawName !== '' ? $rawName : $rawCode;

            if ($codeCol > 0) {
                $parentId = $levelLast[$codeCol - 1] ?? null;
            } else {
                $levelLast = [];
                $parentId = null;
            }

            for ($j = $codeCol + 1; $j < $this->maxCols; $j++) {
                unset($levelLast[$j]);
            }

            $query = ChartOfAccount::query()->where('parent_id', $parentId);
            if ($code !== '') $query->where('code', $code);
            else              $query->where('name', $name);

            $account = $query->first();

            if (!$account) {
                $account = new ChartOfAccount();
                $account->parent_id = $parentId;
                $account->name = $name;
                $account->currency_id = 1;
                if ($code !== '') {
                    $account->code  = $code;
                }
                $account->description = $currentDescription;
                $account->is_accumulative = true;

                if (!empty($currentType)) {
                    $account->type = $currentType;
                }
                $account->save();
            } else {
                $dirty = false;
                if ($account->name !== $name && $name !== '') {
                    $account->name = $name; $dirty = true;
                }
                if ($code !== '' && empty($account->code)) {
                    $account->code = $code; $dirty = true;
                }
                if (!empty($currentType) && empty($account->type)) {
                    $account->type = $currentType; $dirty = true;
                }
                 if (empty($account->description) && $currentDescription) {
                     $account->description = $currentDescription; $dirty = true;
                 }
                $account->currency_id = 1;
                if ($dirty) $account->save();
            }

            $levelLast[$codeCol] = $account->id;
        }
    }


    protected function looksLikeCode(?string $s): bool
    {
        if ($s === null) return false;
        $s = preg_replace('/\x{00A0}|\x{2000}-\x{200B}/u', ' ', $s);
        $s = trim($s);
        if ($s === '') return false;

        return (bool) preg_match('/^\d+(?:\.\d+)*$/u', $s);
    }

    protected function inferTypeFromHeader(?string $headerText, ?string $neighborName = null): ?string
    {
        $text = $this->normalize(($headerText ?? '') . ' ' . ($neighborName ?? ''));

        if (preg_match('/ԿԱՐԳ\s*1\s*-\s*2/u', $text) || mb_strpos($text, 'ԱԿՏԻՎՆԵՐ') !== false) {
            return 'active';
        }

        if (preg_match('/ԿԱՐԳ\s*3\s*-\s*4/u', $text) || mb_strpos($text, 'ՊԱՐՏԱՎՈՐՈՒԹՅՈՒՆՆԵՐ') !== false) {
            return 'passive';
        }

        if (preg_match('/ԿԱՐԳ\s*5(?!\d)/u', $text) || mb_strpos($text, 'ՍԵՓԱԿԱՆ ԿԱՊԻՏԱԼ') !== false) {
            return 'equity';
        }

        if (preg_match('/ԿԱՐԳ\s*6(?!\d)/u', $text) || mb_strpos($text, 'ՀԱՄԱՊԱՐՓԱԿ ԵԿԱՄՈՒՏՆԵՐ') !== false || mb_strpos($text, 'ԵԿԱՄՈՒՏ') !== false) {
            return 'income';
        }

        if (preg_match('/ԿԱՐԳ\s*7(?!\d)/u', $text) || mb_strpos($text, 'ՀԱՄԱՊԱՐՓԱԿ ԾԱԽՍԵՐ') !== false || mb_strpos($text, 'ԾԱԽՍ') !== false) {
            return 'expense';
        }

        if (preg_match('/ԿԱՐԳ\s*8(?!\d)/u', $text) || mb_strpos($text, 'ՀԵՏՀԱՇՎԵԿՇՌԱՅԻՆ') !== false || mb_strpos($text, 'OFF') !== false) {
            return 'off_balance';
        }

        return null;
    }

    protected function normalize(?string $s): string
    {
        if ($s === null) return '';
        $s = preg_replace('/\x{00A0}|\x{2000}-\x{200B}/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);
        return mb_strtoupper($s, 'UTF-8');
    }
}

