<?php

namespace App\Support;

/** Document types a loan application's files can carry (stored in files.doc_type). */
final class LoanApplicationDocument
{
    public const TECH_PASSPORT = 'tech_passport';
    public const PHOTO = 'photo';
    public const OWNERSHIP_CERTIFICATE = 'ownership_certificate';
    public const ACRA = 'acra';
    public const OTHER = 'other';

    public const TYPES = [
        self::TECH_PASSPORT,
        self::PHOTO,
        self::OWNERSHIP_CERTIFICATE,
        self::ACRA,
        self::OTHER,
    ];

    public const REQUIRED = [
        'car'      => [self::TECH_PASSPORT, self::PHOTO],
        'property' => [self::OWNERSHIP_CERTIFICATE],
    ];

    public const LABELS = [
        self::TECH_PASSPORT          => 'Տեխ. անձնագիր',
        self::PHOTO                  => 'Լուսանկար',
        self::OWNERSHIP_CERTIFICATE  => 'Սեփականության վկայական',
        self::ACRA                   => 'ԱՔՌԱ',
        self::OTHER                  => 'Այլ ֆայլ',
    ];
}
