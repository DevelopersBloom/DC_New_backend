<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;

/**
 * Generates L001 XML for CBA Credit Registry (lnreg3).
 *
 * XSD namespace : urn:cba-am:lnreg3
 * stDate format : dd/MM/yyyy
 * stTime format : HH:mm:ss
 * CreditCode    : NNNNN-NNNNNNNN-NNNNNN
 *                 (5 bank)(8 yyyymmdd)(5 seq)(1 checksum)
 */
class CreditRegistryL001Service
{
    private const NS     = 'urn:cba-am:lnreg3';

    /** Վարկատու կազմակերպության 5-նիշ գրանցման կոդ */
    private const ORG_CODE   = '66100';

    /** Մասնաճյուղի 5-նիշ կոդ */
    private const BRANCH_CODE = '00001';

    /** Կազմակերպության կարգավիճակ: 1=Գործող */
    private const ORG_STATUS  = 1;

    // =========================================================================
    // Public
    // =========================================================================

    public function generateL001Xml(Contract $contract): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput       = false;
        $dom->preserveWhiteSpace = false;

        $root = $dom->createElementNS(self::NS, 'L001');
        $dom->appendChild($root);

        $root->appendChild($this->buildReportHeader($dom));
        $root->appendChild($dom->createElement('CreditCode', $this->buildCreditCode($contract)));
        $root->appendChild($this->buildLoanData($dom, $contract));

        return $dom->saveXML($dom->documentElement);
    }

    // =========================================================================
    // ReportHeader   (ctReportHeader)
    // =========================================================================

    private function buildReportHeader(DOMDocument $dom): DOMElement
    {
        $now = Carbon::now();

        $header = $dom->createElement('ReportHeader');
        $header->appendChild($dom->createElement('OrganisationCode',       self::ORG_CODE));
        $header->appendChild($dom->createElement('OrganisationBranchCode', self::BRANCH_CODE));
        $header->appendChild($dom->createElement('OrganizationStatus',     (string) self::ORG_STATUS));

        $dt = $dom->createElement('SendDateTime');
        $dt->appendChild($dom->createElement('Date', $now->format('d/m/Y')));  // stDate: dd/MM/yyyy
        $dt->appendChild($dom->createElement('Time', $now->format('H:i:s'))); // stTime: HH:mm:ss
        $header->appendChild($dt);

        return $header;
    }

    // =========================================================================
    // CreditCode   (stLoanRegisterCode: ^[0-9]{5}-[0-9]{8}-[0-9]{6}$)
    // =========================================================================

    /**
     * Կառուցվածք: {bank(5)}-{yyyymmdd(8)}-{seq(5)}{checksum(1)}
     * Checksum   : CBA Luhn mod-10 (հաշվարկ 18 թվից, checksum-ն ընդ. չի)
     *
     * Փաստաթղթի օրինակ: 80500-20161228-12378 → checksum=5
     * Ստացվում է       : 80500-20161228-123785
     */
    private function buildCreditCode(Contract $contract): string
    {
        $bank = self::ORG_CODE;                                                   // 5 թ.
        $date = Carbon::parse($contract->date)->format('Ymd');                    // 8 թ.
        $seq  = str_pad((string) ($contract->id % 99999), 5, '0', STR_PAD_LEFT); // 5 թ.
        $base = $bank . $date . $seq;                                             // 18 թ.
        $cs   = $this->cbaChecksum($base);                                        // 1 թ.

        return sprintf('%s-%s-%s%d', $bank, $date, $seq, $cs);
    }

    /**
     * CBA Luhn mod-10
     *
     * I.   Աջ կողմից 1-ին, 3-րդ, 5-րդ ... (index 0,2,4) դիրքերը × 2
     * II.  Կրկնապատկածի յուրաքանչյուր թվանշան + մնացածը — ամփոփել
     * III. Ստուգիչ = (10 - (sum % 10)) % 10
     */
    private function cbaChecksum(string $digits): int
    {
        $sum = 0;
        $rev = strrev($digits);

        for ($i = 0, $len = strlen($rev); $i < $len; $i++) {
            $d = (int) $rev[$i];
            if ($i % 2 === 0) {
                $doubled = $d * 2;
                // 16 → 1+6=7
                $sum += (int) ($doubled / 10) + ($doubled % 10);
            } else {
                $sum += $d;
            }
        }

        return (10 - ($sum % 10)) % 10;
    }

    // =========================================================================
    // LoanData   (ctLoan)  — XSD-ի հաջորդականությամբ
    // =========================================================================

    private function buildLoanData(DOMDocument $dom, Contract $contract): DOMElement
    {
        $contract->loadMissing(['client', 'currency']);
        $client = $contract->client;

        $ld = $dom->createElement('LoanData');

        // ------------------------------------------------------------------ //
        // 1. DebtorID — stClientID: [0-9]{13}
        //    ԿԲ-ի BankID (13 թ.)  —  ՈՉ social_card, ՈՉ tax_number
        // ------------------------------------------------------------------ //
        $debtorId = (string) ($client?->bank_id ?? '');
        $ld->appendChild($dom->createElement('DebtorID', $debtorId));

        // ------------------------------------------------------------------ //
        // 2. IsPE — stYN (Y/N), ոչ պարտ.
        //    Y = ֆիզ. անձ վերցրել է որպես ԱՁ
        // ------------------------------------------------------------------ //
        $ld->appendChild($dom->createElement('IsPE',
            ($client?->is_individual_entrepreneur) ? 'Y' : 'N'
        ));

        // ------------------------------------------------------------------ //
        // 3. AffectionWithCreditor — stYN, պարտ.
        // ------------------------------------------------------------------ //
        $ld->appendChild($dom->createElement('AffectionWithCreditor',
            ($client?->is_linked_to_company) ? 'Y' : 'N'
        ));

        // ------------------------------------------------------------------ //
        // 4. ContractType — stContractType: 1=Պարզ, 2=Համատեղ, 3=Խմբային
        // ------------------------------------------------------------------ //
        $ct = max(1, min(3, (int) ($contract->contract_kind ?? 1)));
        $ld->appendChild($dom->createElement('ContractType', (string) $ct));

        // ------------------------------------------------------------------ //
        // 5. ContractNumber — xs:string, min 1, max 20
        // ------------------------------------------------------------------ //
        $num = substr((string) ($contract->num ?? $contract->id), 0, 20);
        $ld->appendChild($dom->createElement('ContractNumber', $this->esc($num)));

        // ------------------------------------------------------------------ //
        // 6. ContractDate — stDate: dd/MM/yyyy
        // ------------------------------------------------------------------ //
        $ld->appendChild($dom->createElement('ContractDate',
            Carbon::parse($contract->date)->format('d/m/Y')
        ));

        // ------------------------------------------------------------------ //
        // 7. RepaymentDate — stDate: dd/MM/yyyy (ըստ պայմ.)
        // ------------------------------------------------------------------ //
        $ld->appendChild($dom->createElement('RepaymentDate',
            Carbon::parse($contract->deadline)->format('d/m/Y')
        ));

        // ------------------------------------------------------------------ //
        // 8. LoanType — stLoanType: 0–18
        // ------------------------------------------------------------------ //
        $lt = max(0, min(18, (int) ($contract->loan_type ?? 0)));
        $ld->appendChild($dom->createElement('LoanType', (string) $lt));

        // ------------------------------------------------------------------ //
        // 9. Currency — stCurrency: [A-Z]{3}  ISO 4217
        // ------------------------------------------------------------------ //
        $cur = strtoupper($contract->currency?->code ?? 'AMD');
        $ld->appendChild($dom->createElement('Currency', $cur));

        // ------------------------------------------------------------------ //
        // 10. ContractAmount — stAmountNonZero: > 0, 2 decimal
        // ------------------------------------------------------------------ //
        $contractAmt = (float) ($contract->contract_amount ?? 0);
        $ld->appendChild($dom->createElement('ContractAmount',
            $this->fmtAmountNonZero($contractAmt)
        ));

        // ------------------------------------------------------------------ //
        // 11. ContractModifiedAmount — stAmountNonZero
        //     Փոփոխված սահմ.; եթե 0 (վարկ չի տրամ.) → contract_amount
        // ------------------------------------------------------------------ //
        $modAmt = (float) ($contract->provided_amount ?? 0);
        if ($modAmt <= 0) {
            $modAmt = $contractAmt;
        }
        $ld->appendChild($dom->createElement('ContractModifiedAmount',
            $this->fmtAmountNonZero($modAmt)
        ));

        // ------------------------------------------------------------------ //
        // 12. AnnualInterestRate — stPercent: 0–100, 2 decimal
        //     interest_rate = օրային  →  × 365
        // ------------------------------------------------------------------ //
        $annual = round((float) ($contract->interest_rate ?? 0) * 365, 2);
        $ld->appendChild($dom->createElement('AnnualInterestRate',
            $this->fmtPercent($annual)
        ));

        // ------------------------------------------------------------------ //
        // 13. ActualInterestRate — stPercent
        //     effective_annual_rate կամ annual fallback
        // ------------------------------------------------------------------ //
        $actual = (float) ($contract->effective_annual_rate ?? $annual);
        $ld->appendChild($dom->createElement('ActualInterestRate',
            $this->fmtPercent($actual)
        ));

        // ------------------------------------------------------------------ //
        // 14. InterestRateType — stInterestRateType: 1=Լող., 2=Ֆիքս., 3=Փոփ.
        // ------------------------------------------------------------------ //
        $irt = max(1, min(3, (int) ($contract->interest_rate_type ?? 2)));
        $ld->appendChild($dom->createElement('InterestRateType', (string) $irt));

        // ------------------------------------------------------------------ //
        // 15. IsInterestSubsidy — stYN
        // ------------------------------------------------------------------ //
        $ld->appendChild($dom->createElement('IsInterestSubsidy', 'N'));

        // ------------------------------------------------------------------ //
        // 16. ProvisionOfCredit — stYN
        //     Y = Միջազգային ծրագրով, N = ոչ
        // ------------------------------------------------------------------ //
        $ld->appendChild($dom->createElement('ProvisionOfCredit', 'N'));

        // ------------------------------------------------------------------ //
        // 17. LoanUseField — stUseField: [0-9]{2}.[0-9]{2}.[0-9]{1}
        //     Appendix Reference Book.xlsx-ից
        // ------------------------------------------------------------------ //
        $luf = (string) ($contract->loan_use_field ?? $client?->activity_field ?? '');
        if (!preg_match('/^[0-9]{2}\.[0-9]{2}\.[0-9]{1}$/', $luf)) {
            $luf = '10.01.1'; // կանխադրված — Ֆիզ. անձ, Սպառողական
        }
        $ld->appendChild($dom->createElement('LoanUseField', $luf));

        // ------------------------------------------------------------------ //
        // 18. LoanUseCountry — stCountry: [A-Z]{3}  ISO 3166
        // ------------------------------------------------------------------ //
        $ld->appendChild($dom->createElement('LoanUseCountry', 'ARM'));

        // ------------------------------------------------------------------ //
        // 19. LoanUseRegion — stRegion: [0-9]{8}
        //     ARM_Regions_Districts.pdf-ից
        //     Երևան = 01000000, Արտ. = 99000002
        // ------------------------------------------------------------------ //
        $reg = (string) ($client?->region_code ?? '');
        if (!preg_match('/^[0-9]{8}$/', $reg)) {
            $reg = '01000000'; // fallback Երևան
        }
        $ld->appendChild($dom->createElement('LoanUseRegion', $reg));

        return $ld;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** stAmountNonZero: > 0, fractionDigits=2 */
    private function fmtAmountNonZero(float $v): string
    {
        $v = round($v, 2);
        if ($v <= 0) {
            $v = 0.01;
        }
        return number_format($v, 2, '.', '');
    }

    /** stPercent: 0–100, fractionDigits=2 */
    private function fmtPercent(float $v): string
    {
        return number_format(round(max(0.0, min(100.0, $v)), 2), 2, '.', '');
    }
}
