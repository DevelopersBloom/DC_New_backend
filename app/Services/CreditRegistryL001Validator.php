<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;

/**
 * Validates a Contract against the CBA Credit Registry L001 (ctLoan) XSD
 * BEFORE the XML is generated and sent, collecting every problem instead of
 * failing on the first one (as CreditRegistryL001Service does via exceptions).
 *
 * Source of truth: "Functional Specification - LoanReg_L001_L002_L003_message
 * format" (complexType ctLoan and its referenced simpleTypes: stClientID,
 * stContractType, stDate, stLoanType, stCurrency, stAmountNonZero, stPercent,
 * stInterestRateType, stYN, stUseField, stCountry, stRegion).
 */
class CreditRegistryL001Validator
{
    /**
     * @return string[] Human-readable (Armenian) problem descriptions.
     *                   Empty array = the contract is ready for L001.
     */
    public function validate(Contract $contract): array
    {
        $errors = [];
        $contract->loadMissing(['client', 'currency']);
        $client = $contract->client;

        if (!$client) {
            $errors[] = 'Պայմանագրի Client-ը գոյություն չունի կամ բեռնված չէ';
            return $errors;
        }

        // DebtorID — stClientID: ^[0-9]{13}$
        $debtorId = (string) ($client->bank_client_id ?? '');
        if (!preg_match('/^[0-9]{13}$/', $debtorId)) {
            $errors[] = "DebtorID (հաճախորդի BankID) պարտադիր է, պետք է լինի 13 նիշ, ստացվել է՝ \"{$debtorId}\".";
        }

        // ContractType — stContractType: int 1..3 (1=Պարզ, 2=Համատեղ, 3=Խմբային)
        $contractKind = $contract->contract_kind;
        if ($contractKind === null || (int) $contractKind < 1 || (int) $contractKind > 3) {
            $errors[] = 'ContractType (պայմանագրի տեսակ) պարտադիր է';
        }

        // ContractNumber — xs:string, minLength=1, maxLength=20
        $num = trim((string) ($contract->num ?? $contract->id ?? ''));
        if ($num === '') {
            $errors[] = 'ContractNumber (պայմանագրի համար) պարտադիր է։';
        } elseif (mb_strlen($num) > 20) {
            $errors[] = "ContractNumber-ը (\"{$num}\") գերազանցում է առավելագույն 20 նիշը";
        }

        // ContractDate — stDate: dd/mm/yyyy
        if (empty($contract->date)) {
            $errors[] = 'ContractDate (պայմանագրի կնքման ամսաթիվ) պարտադիր է։';
        }

        // RepaymentDate — stDate, must be >= ContractDate (ER0005)
        if (empty($contract->deadline)) {
            $errors[] = 'RepaymentDate (վարկի վերջնական մարման ամսաթիվ) պարտադիր է։';
        } elseif (!empty($contract->date)) {
            $contractDate  = Carbon::parse($contract->date);
            $repaymentDate = Carbon::parse($contract->deadline);
            if ($repaymentDate->lt($contractDate)) {
                $errors[] = 'RepaymentDate-ը (' . $repaymentDate->toDateString() . ') չի կարող լինել ContractDate-ից (' . $contractDate->toDateString() . ') շուտ (ER0005)։';
            }
        }

        // LoanType — stLoanType: int 0..18
        $loanType = $contract->loan_type;
        if ($loanType === null || (int) $loanType < 0 || (int) $loanType > 18) {
            $errors[] = 'LoanType (վարկի տեսակ) պարտադիր է և պետք է լինի 0-18 միջակայքում, ստացվել է՝ "' . ($loanType ?? 'null') . '"։';
        }

        // Currency — stCurrency: ^[A-Z]{3}$ (ISO 4217)
        $currencyCode = strtoupper((string) ($contract->currency?->code ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            $errors[] = "Currency (արժույթ) պարտադիր է և պետք է լինի ISO 4217 3-տառանոց կոդ, ստացվել է՝ \"{$currencyCode}\"։";
        }

        // ContractAmount — stAmountNonZero: > 0, 2 decimal
        $contractAmount = (float) ($contract->contract_amount ?? 0);
        if ($contractAmount <= 0) {
            $errors[] = 'ContractAmount (պայմանագրով նախատեսված գումար) պարտադիր է և պետք է լինի 0-ից մեծ։';
        }

        // ContractModifiedAmount — stAmountNonZero: > 0, 2 decimal
        $contractModifiedAmount = (float) ($contract->contract_modified_amount ?? $contractAmount);
        if ($contractModifiedAmount <= 0) {
            $errors[] = 'ContractModifiedAmount (փոփոխված սահմանաչափ) պարտադիր է և պետք է լինի 0-ից մեծ';
        }

        // AnnualInterestRate — stPercent: 0..100, 2 decimal (interest_rate/day × 365)
        $annual = round((float) ($contract->interest_rate ?? 0) * 365, 2);
        if ($annual < 0 || $annual > 100) {
            $errors[] = "AnnualInterestRate (հաշվարկված տարեկան տոկոսադրույք) պետք է լինի 0-100%-ի սահմաններում, հաշվարկվել է՝ {$annual}% (contract.interest_rate × 365).";
        }

        // ActualInterestRate — stPercent: 0..100, 2 decimal
        $actual = (float) ($contract->effective_annual_rate ?? $annual);
        if ($actual < 0 || $actual > 100) {
            $errors[] = "ActualInterestRate (փաստացի տոկոսադրույք) պետք է լինի 0-100%-ի սահմաններում, ստացվել է՝ {$actual}%։";
        }

        // InterestRateType — stInterestRateType: int 1..3 (1=Լողացող, 2=Ֆիքսված, 3=Փոփոխվող)
        $irt = $contract->interest_rate_type;
        if ($irt === null || (int) $irt < 1 || (int) $irt > 3) {
            $errors[] = 'InterestRateType (տոկոսադրույքի տեսակ) պարտադիր է և պետք է լինի 1(Լողացող)/2(Ֆիքսված)/3(Փոփոխվող), ստացվել է՝ "' . ($irt ?? 'null') . '"։';
        }

        // LoanUseField — stUseField: [0-9]{2}.[0-9]{2}.[0-9]{1}
        $luf = trim((string) ($contract->loan_use_field ?? $client->activity_field ?? ''));
        if ($luf === '') {
            $errors[] = 'LoanUseField (վարկի օգտագործման ոլորտ) պարտադիր է';
        } elseif (!preg_match('/^[0-9]{2}\.[0-9]{2}\.[0-9]{1}$/', $luf)) {
            $errors[] = "LoanUseField-ի ձևաչափը սխալ է";
        }

        // LoanUsePurpose — Int32, required per CBA ER0026 (added to schema after 2017;
        // not present in this havelvac7 copy of ctLoan, but confirmed by the L005/L006 spec).
        $lup = $contract->loan_use_purpose;
        if ($lup === null || $lup === '') {
            $errors[] = 'LoanUsePurpose (վարկի օգտագործման նպատակ) պարտադիր է';
        } elseif (!is_numeric($lup)) {
            $errors[] = "LoanUsePurpose-ը պետք է լինի ամբողջ թիվ, ստացվել է՝ \"{$lup}\"։";
        }

        // LoanUseRegion — stRegion: ^[0-9]{8}$
        $region = trim((string) ($client->region_code ?? ''));
        if ($region === '') {
            $errors[] = 'LoanUseRegion (վարկի օգտագործման մարզ) պարտադիր է';
        } elseif (!preg_match('/^[0-9]{8}$/', $region)) {
            $errors[] = "LoanUseRegion-ի ձևաչափը սխալ է (\"{$region}\")";
        }

        return $errors;
    }
}
