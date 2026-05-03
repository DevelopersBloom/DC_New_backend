<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;

/**
 * Generates L001 XML for Credit Registry (CBA lnreg3).
 * Root: L001, namespace: urn:cba-am:lnreg3
 * Children order: ReportHeader, CreditCode, LoanData
 *
 * XSD: urn:cba-am:lnreg3
 * Date format: dd/MM/yyyy  (stDate)
 * Time format: HH:mm:ss    (stTime)
 * CreditCode: NNNNN-NNNNNNNN-NNNNNN (5 bank + 8 date + 5 seq + 1 checksum)
 */
class CreditRegistryL001Service
{
    private const NS = 'urn:cba-am:lnreg3';

    /** Վարկատու կազմակերպության գրանցման համարը (5 թիվ) */
    private const ORGANISATION_CODE = '66100';

    /** Մասնաճյուղի կոդը (5 թիվ) */
    private const ORGANISATION_BRANCH_CODE = '00001';

    /** Վարկատուի կարգավիճակը: 1=Գործող, 2=Սնանկ, 3=Լուծ. փուլ, 4=Լուծ. */
    private const ORGANIZATION_STATUS = 1;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function generateL001Xml(Contract $contract): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput        = false; // կարևոր է XML ստորագրության համար
        $dom->preserveWhiteSpace  = false;

        // Root — default namespace, բոլոր երեխաները ժառանգում են
        $root = $dom->createElementNS(self::NS, 'L001');
        $dom->appendChild($root);

        // 1. ReportHeader
        $root->appendChild($this->createReportHeader($dom));

        // 2. CreditCode
        $root->appendChild($dom->createElement('CreditCode', $this->buildCreditCode($contract)));

        // 3. LoanData
        $root->appendChild($this->createLoanData($dom, $contract));

        return $dom->saveXML($dom->documentElement);
    }

    // -------------------------------------------------------------------------
    // ReportHeader
    // -------------------------------------------------------------------------

    private function createReportHeader(DOMDocument $dom): DOMElement
    {
        $header = $dom->createElement('ReportHeader');

        $header->appendChild($dom->createElement('OrganisationCode',       self::ORGANISATION_CODE));
        $header->appendChild($dom->createElement('OrganisationBranchCode', self::ORGANISATION_BRANCH_CODE));
        $header->appendChild($dom->createElement('OrganizationStatus',     (string) self::ORGANIZATION_STATUS));

        // stDate = dd/MM/yyyy, stTime = HH:mm:ss
        $now          = Carbon::now();
        $sendDateTime = $dom->createElement('SendDateTime');
        $sendDateTime->appendChild($dom->createElement('Date', $now->format('d/m/Y')));
        $sendDateTime->appendChild($dom->createElement('Time', $now->format('H:i:s')));
        $header->appendChild($sendDateTime);

        return $header;
    }

    // -------------------------------------------------------------------------
    // CreditCode — NNNNN-NNNNNNNN-NNNNNN
    // -------------------------------------------------------------------------

    /**
     * Ձևաչափ: {bank(5)}-{yyyymmdd(8)}-{seq(5)}{checksum(1)}
     * Ստուգիչ նիշ — CBA Luhn mod 10 (18 թվանշան, checksum-ն ընդ. չի)
     */
    private function buildCreditCode(Contract $contract): string
    {
        $orgCode  = self::ORGANISATION_CODE;                              // 5 թիվ
        $datePart = Carbon::parse($contract->date)->format('Ymd');        // 8 թիվ
        $sequence = str_pad((string) ($contract->id % 99999), 5, '0', STR_PAD_LEFT); // 5 թիվ

        $base     = $orgCode . $datePart . $sequence;                     // 18 թիվ
        $checksum = $this->calculateCbaChecksum($base);                   // 1 թիվ

        // Ձևաչափ: 66100-20240115-123785  (= 5+8+5+1 = 19 թիվ + 2 գծ)
        return sprintf('%s-%s-%s%d', $orgCode, $datePart, $sequence, $checksum);
    }

    /**
     * CBA Luhn Mod 10:
     * I.   Աջ կողմից՝ 1., 3., 5. ... դիրքերի թվանշանները կրկնապատկել
     * II.  Բոլոր թվանշանների (կրկնապատկվածների բոլոր թվերի) գումարը
     * III. Մոտ 10-ի բազմապատիկ − գումար = ստուգիչ նիշ (0 եթե ≡ 0)
     */
    private function calculateCbaChecksum(string $input): int
    {
        $sum      = 0;
        $reversed = strrev($input);
        $len      = strlen($reversed);

        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $reversed[$i];

            if ($i % 2 === 0) {
                // Կրկնապատկել և գումարել թվանշանները (16 → 1+6=7)
                $doubled = $digit * 2;
                $sum    += array_sum(str_split((string) $doubled));
            } else {
                $sum += $digit;
            }
        }

        $remainder = $sum % 10;
        return ($remainder === 0) ? 0 : (10 - $remainder);
    }

    // -------------------------------------------------------------------------
    // LoanData (ctLoan)
    // -------------------------------------------------------------------------

    private function createLoanData(DOMDocument $dom, Contract $contract): DOMElement
    {
        $contract->loadMissing(['client', 'currency']);
        $client = $contract->client;

        $loanData = $dom->createElement('LoanData');

        // 1. DebtorID — 13 թվանշան (stClientID)
        //    Ֆիզ. անձ → social_card_number, ՍՊԸ/ԲԲԸ → tax_number
        $debtorId = '';
        if ($client) {
            $debtorId = $client->type === 'legal'
                ? ($client->tax_number          ?? '')
                : ($client->social_card_number   ?? '');
        }
        $loanData->appendChild($dom->createElement('DebtorID', $this->esc($debtorId)));

        // 2. IsPE — ֆիզ. անձը վերցրել է որպես ԱՁ (Y/N), ոչ պարտ.
        $isPe = ($client && !empty($client->is_individual_entrepreneur)) ? 'Y' : 'N';
        $loanData->appendChild($dom->createElement('IsPE', $isPe));

        // 3. AffectionWithCreditor — կապված է վարկատուին (Y/N)
        $affection = ($client && !empty($client->is_linked_to_company)) ? 'Y' : 'N';
        $loanData->appendChild($dom->createElement('AffectionWithCreditor', $affection));

        // 4. ContractType — 1=Պարզ, 2=Համատեղ, 3=Խմբային (stContractType: 1–3)
        $contractType = (int) ($contract->contract_kind ?? 1);
        $contractType = max(1, min(3, $contractType));
        $loanData->appendChild($dom->createElement('ContractType', (string) $contractType));

        // 5. ContractNumber — max 20 նիշ
        $contractNum = substr($contract->num ?? (string) $contract->id, 0, 20);
        $loanData->appendChild($dom->createElement('ContractNumber', $this->esc($contractNum)));

        // 6. ContractDate — stDate: dd/MM/yyyy
        $contractDate = $contract->date
            ? Carbon::parse($contract->date)->format('d/m/Y')
            : '';
        $loanData->appendChild($dom->createElement('ContractDate', $contractDate));

        // 7. RepaymentDate — stDate: dd/MM/yyyy (ըստ պայմ.)
        $repaymentDate = $contract->deadline
            ? Carbon::parse($contract->deadline)->format('d/m/Y')
            : '';
        $loanData->appendChild($dom->createElement('RepaymentDate', $repaymentDate));

        // 8. LoanType — 0–18 (stLoanType)
        $loanType = (int) ($contract->loan_type ?? 0);
        $loanType = max(0, min(18, $loanType));
        $loanData->appendChild($dom->createElement('LoanType', (string) $loanType));

        // 9. Currency — ISO 4217 Alpha-3 (stCurrency: [A-Z]{3})
        $currencyCode = $contract->currency ? strtoupper($contract->currency->code) : 'AMD';
        $loanData->appendChild($dom->createElement('Currency', $currencyCode));

        // 10. ContractAmount — > 0, 2 տասնորդ (stAmountNonZero)
        $contractAmount = (float) ($contract->contract_amount ?? 0);
        $loanData->appendChild($dom->createElement('ContractAmount', $this->fmtAmount($contractAmount)));

        // 11. ContractModifiedAmount — > 0, 2 տասնորդ (փոփոխված սահմ.)
        $modifiedAmount = (float) ($contract->provided_amount ?? $contractAmount);
        $loanData->appendChild($dom->createElement('ContractModifiedAmount', $this->fmtAmount($modifiedAmount)));

        // 12. AnnualInterestRate — 0–100, 2 տասնորդ (անվ. տոկ.)
        //     contract->interest_rate-ն օրային տոկոս ենթ. → × 365
        $annualRate = (float) ($contract->interest_rate ?? 0) * 365;
        $loanData->appendChild($dom->createElement('AnnualInterestRate', $this->fmtPercent($annualRate)));

        // 13. ActualInterestRate — 0–100, 2 տասնորդ (փաստ. տոկ.)
        $actualRate = (float) ($contract->effective_annual_rate ?? $annualRate);
        $loanData->appendChild($dom->createElement('ActualInterestRate', $this->fmtPercent($actualRate)));

        // 14. InterestRateType — 1=Լողացող, 2=Ֆիքսված, 3=Փոփոխվող
        $rateType = (int) ($contract->interest_rate_type ?? 2);
        $rateType = max(1, min(3, $rateType));
        $loanData->appendChild($dom->createElement('InterestRateType', (string) $rateType));

        // 15. IsInterestSubsidy — Y=սուբ., N=ոչ
        $loanData->appendChild($dom->createElement('IsInterestSubsidy', 'N'));

        // 16. ProvisionOfCredit — Y=Միջ. ծրագ., N=ոչ  *** ՊԱՐՏ. ***
        $loanData->appendChild($dom->createElement('ProvisionOfCredit', 'N'));

        // 17. LoanUseField — NN.NN.N ձևաչափ  *** ՊԱՐՏ. ***
        //     Appendix - Reference Book.xlsx-ից ճիշտ կոդ
        $loanUseField = $contract->loan_use_field
            ?? ($client?->activity_field ?? '00.00.0');
        $loanData->appendChild($dom->createElement('LoanUseField', $this->esc($loanUseField)));

        // 18. LoanUseCountry — ISO 3166 Alpha-3 ([A-Z]{3})
        $loanData->appendChild($dom->createElement('LoanUseCountry', 'ARM'));

        // 19. LoanUseRegion — 8 թիվ (stRegion)
        $loanUseRegion = $client?->region_code ?? $client?->actual_province ?? '01000000';
        $loanData->appendChild($dom->createElement('LoanUseRegion', $this->esc($loanUseRegion)));

        return $loanData;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** XML-safe escape */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** stAmount / stAmountNonZero — 2 տասնորդ, min 0.01 */
    private function fmtAmount(float $value): string
    {
        $v = round($value, 2);
        if ($v <= 0) {
            $v = 0.01; // stAmountNonZero պահանջ > 0
        }
        return number_format($v, 2, '.', '');
    }

    /** stPercent — 0–100, 2 տասնորդ */
    private function fmtPercent(float $value): string
    {
        $v = round(max(0.0, min(100.0, $value)), 2);
        return number_format($v, 2, '.', '');
    }
}
