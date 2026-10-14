<?php

namespace App\Support;

/**
 * CBA credit registry reference "Վարկի օգտագործման նպատակ" (stUsePurpose,
 * Havelvac2). Codes are 0..14; some are restricted by client type.
 */
class LoanUsePurpose
{
    public const CODES = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14];

    /** Individuals only. */
    public const INDIVIDUAL_ONLY = [5, 11, 12];

    /** Legal entities and sole proprietors only. */
    public const LEGAL_ONLY = [13, 14];

    /** Legal entities; individuals only when the activity is agriculture. */
    public const CURRENT_ASSETS = 8;

    /** loan_use_field group prefix for agriculture (01.xxx). */
    private const AGRICULTURE_FIELD_PREFIX = '01.';

    /**
     * @param  string|null $clientType      'individual' | 'legal'
     * @param  string|null $loanUseField    e.g. "01.002"
     * @return string|null error message, null when the combination is allowed
     */
    public static function violation(int $code, ?string $clientType, ?string $loanUseField = null): ?string
    {
        if (!in_array($code, self::CODES, true)) {
            return 'Վարկի օգտագործման նպատակի արժեքը պետք է լինի 0-ից 14։';
        }

        if ($clientType === 'individual') {
            if (in_array($code, self::LEGAL_ONLY, true)) {
                return 'Այս նպատակը թույլատրելի է միայն իրավաբանական անձանց և անհատ ձեռնարկատերերի համար։';
            }
            if ($code === self::CURRENT_ASSETS
                && !str_starts_with((string) $loanUseField, self::AGRICULTURE_FIELD_PREFIX)) {
                return 'Ընթացիկ ակտիվների նպատակը ֆիզիկական անձանց համար թույլատրելի է միայն գյուղատնտեսության ոլորտում։';
            }
        } elseif ($clientType === 'legal') {
            if (in_array($code, self::INDIVIDUAL_ONLY, true)) {
                return 'Այս նպատակը թույլատրելի է միայն ֆիզիկական անձանց համար։';
            }
        }

        return null;
    }
}
