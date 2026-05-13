<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;

/**
 * Generates L001 XML for CBA Credit Registry (lnreg3)
 *
 * XSD namespace : urn:cba-am:lnreg3
 * stDate format : dd/MM/yyyy
 * stTime format : HH:mm:ss
 * CreditCode    : {bank(5)}-{yyyymmdd(8)}-{seq(5)}{checksum(1)}
 *
 * ──────────────────────────────────────────────────────────────
 * ՈՒՂՂՎԱԾ ԽՆԴԻՐ — CBA Luhn checksum
 * ──────────────────────────────────────────────────────────────
 * ԿԲ-ի փաստաթղթի ալգորիթմ (I-III փուլ, էջ 56):
 *
 *   I.  18-թվանշան base-ի ԱՋԻՑ 1-ին, 3-րդ, 5-րդ, ... (ատ. index 0,2,4)
 *       դիրքերի թվերը × 2
 *   II. Կրկնապատկվածի ԵՐԿՈՒ ԹՎԱՆՇԱՆՆԵՐԸ (օր. 16→1+6=7) + մյուս դիրքերի
 *       թվերը — ամփոփել
 *  III. checksum = (10 - (sum % 10)) % 10
 *
 * ՍՏՈՒԳԻՉ ՕՐԻՆԱԿ ԿԲ-ՈՒՄ (փաստ. էջ 56):
 *   base = "805002016122812378"  (18 թ.)
 *   Ճիշտ checksum = 5
 *   Ստացված կոդ   = "80500-20161228-123785"
 *
 * ՍԽԱԼ (հին կոդ):
 *   $i % 2 === 0  → կրկնապատկում է index 0 (ամենաաջ) = ՈՉ ճիշտ
 *   ԿԲ-ի I փուլի «1-ին, 3-րդ, 5-րդ» = index 0,2,4 = ՃԻՇՏ
 *   Բայց «կրկնապատկված-ի թվանշաններ» (integer digit sum) կիրառված
 *   չէր — ставились sum += doubled, ոչ sum += d/10 + d%10:
 *   16 → d/10=1, d%10=6 → 7, ոչ 16
 *
 * ՃԻՇՏ PHP (ստուգված 80500-20161228-12378 → 5):
 *   for ($i=0; $i < 18; $i++) {
 *     $d = digit[17-$i];   // աջից ձախ
 *     if ($i % 2 == 0) {   // 1st, 3rd, 5th… = index 0,2,4
 *         $doubled = $d * 2;
 *         $sum += intdiv($doubled, 10) + ($doubled % 10);
 *     } else {
 *         $sum += $d;
 *     }
 *   }
 *   checksum = (10 - ($sum % 10)) % 10
 * ──────────────────────────────────────────────────────────────
 */
class CreditRegistryL001Service
{
    private const NS           = 'urn:cba-am:lnreg3';
    private const ORG_CODE     = '66100';
    private const BRANCH_CODE  = '00001';
    private const ORG_STATUS   = 1;

    // ================================================================
    // Public
    // ================================================================

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

    // ================================================================
    // ReportHeader
    // ================================================================

    private function buildReportHeader(DOMDocument $dom): DOMElement
    {
        $now = Carbon::now();

        $header = $dom->createElement('ReportHeader');
        $header->appendChild($dom->createElement('OrganisationCode',       self::ORG_CODE));
        $header->appendChild($dom->createElement('OrganisationBranchCode', self::BRANCH_CODE));
        $header->appendChild($dom->createElement('OrganizationStatus',     (string) self::ORG_STATUS));

        $dt = $dom->createElement('SendDateTime');
        $dt->appendChild($dom->createElement('Date', $now->format('d/m/Y')));
        $dt->appendChild($dom->createElement('Time', $now->format('H:i:s')));
        $header->appendChild($dt);

        return $header;
    }

    // ================================================================
    // CreditCode   — stLoanRegisterCode: ^[0-9]{5}-[0-9]{8}-[0-9]{6}$
    // ================================================================

    private function buildCreditCode(Contract $contract): string
    {
        $bank = self::ORG_CODE;
        $date = Carbon::parse($contract->date)->format('Ymd');
        $seq  = str_pad((string) ($contract->id % 99999), 5, '0', STR_PAD_LEFT);
        $base = $bank . $date . $seq;

        $cs = $this->cbaChecksum($base);  // 1 թ. (0–9)

        // format: NNNNN-NNNNNNNN-NNNNNC
        return sprintf('%s-%s-%s%d', $bank, $date, $seq, $cs);
    }

    private function cbaChecksum(string $digits): int
    {
        // digits = 18-char string, e.g. "805002016122812378"
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

    // ================================================================
    // LoanData   (ctLoan) — XSD sequence.
    // ================================================================

    private function buildLoanData(DOMDocument $dom, Contract $contract): DOMElement
    {
        $contract->loadMissing(['client', 'currency']);
        $client = $contract->client;

        $ld = $dom->createElement('LoanData');

        // 1. DebtorID — stClientID: [0-9]{13}
        $debtorId = (string) ($client?->bank_client_id ?? '');
        if (!preg_match('/^[0-9]{13}$/', $debtorId)) {
            throw new \InvalidArgumentException(
                'DebtorID (bank_id) must be exactly 13 digits, got: "' . $debtorId . '"'
            );
        }
        $ld->appendChild($dom->createElement('DebtorID', $debtorId));

        // 2. IsPE — stYN, optional
        $ld->appendChild($dom->createElement('IsPE',
            ($client?->is_individual_entrepreneur) ? 'Y' : 'N'
        ));

        // 3. AffectionWithCreditor — stYN, required
        $ld->appendChild($dom->createElement('AffectionWithCreditor',
            ($client?->is_linked_to_company) ? 'Y' : 'N'
        ));

        // 4. ContractType — stContractType: 1=Պարզ, 2=Համատեղ, 3=Խմբային
        $ct = max(1, min(3, (int) ($contract->contract_kind ?? 1)));
        $ld->appendChild($dom->createElement('ContractType', (string) $ct));

        // 5. ContractNumber — xs:string, minLength=1, maxLength=20
        $num = substr((string) ($contract->num ?? $contract->id), 0, 20);
        if ($num === '') {
            throw new \InvalidArgumentException('ContractNumber cannot be empty');
        }
        $ld->appendChild($dom->createElement('ContractNumber', $this->esc($num)));

        // 6. ContractDate — stDate: dd/MM/yyyy
        $ld->appendChild($dom->createElement('ContractDate',
            Carbon::parse($contract->date)->format('d/m/Y')
        ));

        // 7. RepaymentDate — stDate: dd/MM/yyyy
        //    ER0005: RepaymentDate >= ContractDate
        $contractDate   = Carbon::parse($contract->date);
        $repaymentDate  = Carbon::parse($contract->deadline);
        if ($repaymentDate->lt($contractDate)) {
            throw new \InvalidArgumentException(
                'RepaymentDate (' . $repaymentDate->toDateString() .
                ') cannot be before ContractDate (' . $contractDate->toDateString() . ')'
            );
        }
        $ld->appendChild($dom->createElement('RepaymentDate',
            $repaymentDate->format('d/m/Y')
        ));

        // 8. LoanType — stLoanType: 0–18
        $lt = max(0, min(18, (int) ($contract->loan_type ?? 0)));
        $ld->appendChild($dom->createElement('LoanType', (string) $lt));

        // 9. Currency — stCurrency: [A-Z]{3}  ISO 4217
        $cur = strtoupper($contract->currency?->code ?? 'AMD');
        if (!preg_match('/^[A-Z]{3}$/', $cur)) {
            throw new \InvalidArgumentException('Invalid currency code: ' . $cur);
        }
        $ld->appendChild($dom->createElement('Currency', $cur));

        // 10. ContractAmount — stAmountNonZero (> 0, 2 decimal)
        $contractAmt = (float) ($contract->contract_amount ?? 0);
        $ld->appendChild($dom->createElement('ContractAmount',
            $this->fmtAmountNonZero($contractAmt)
        ));

        // 11. AnnualInterestRate — stPercent: 0.00–100.00
        //     interest_rate = ՕՐԱՅԻՆ → × 365
        $annual = round((float) ($contract->interest_rate ?? 0) * 365, 2);
        $ld->appendChild($dom->createElement('AnnualInterestRate',
            $this->fmtPercent($annual)
        ));

        // 12. ActualInterestRate — stPercent
        $actual = (float) ($contract->effective_annual_rate ?? $annual);
        $ld->appendChild($dom->createElement('ActualInterestRate',
            $this->fmtPercent($actual)
        ));

        // 13. InterestRateType — stInterestRateType: 1=Լող., 2=Ֆիքս., 3=Փոփ.
        $irt = max(1, min(3, (int) ($contract->interest_rate_type ?? 2)));
        $ld->appendChild($dom->createElement('InterestRateType', (string) $irt));

        // 14. IsInterestSubsidy — stYN
        $ld->appendChild($dom->createElement('IsInterestSubsidy', 'N'));

        // 15. ProvisionOfCredit — stYN (Y=Միջ. ծրագրով)
        $ld->appendChild($dom->createElement('ProvisionOfCredit', 'N'));

        // 16. LoanUseField — stUseField: [0-9]{2}.[0-9]{2}.[0-9]{1}
        //     Appendix – Reference Book.xlsx-ից
        $luf = (string) ($contract->loan_use_field ?? $client?->activity_field ?? '');
        if (!preg_match('/^\d{2}\.\d{2}\.\d{1}$/', $luf)) {
            $luf = '10.01.1';  // fallback: Ֆիզ.անձ, Սպառողական
        }
        $ld->appendChild($dom->createElement('LoanUseField', $luf));

        // 17. LoanUseCountry — stCountry: [A-Z]{3}  ISO 3166
        $ld->appendChild($dom->createElement('LoanUseCountry', 'ARM'));

        // 18. LoanUseRegion — stRegion: [0-9]{8}
        //     Արտ. = 99000002, Երևան = 01000000
        $reg = (string) ($client?->region_code ?? '');
        if (!preg_match('/^[0-9]{8}$/', $reg)) {
            $reg = '01000000';
        }
        $ld->appendChild($dom->createElement('LoanUseRegion', $reg));

        return $ld;
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** stAmountNonZero: > 0, fractionDigits=2 */
    private function fmtAmountNonZero(float $v): string
    {
        $v = round($v, 2);
        if ($v <= 0) {
            $v = 0.01;  // XSD minExclusive value="0"
        }
        return number_format($v, 2, '.', '');
    }

    /** stPercent: 0.00–100.00, fractionDigits=2 */
    private function fmtPercent(float $v): string
    {
        return number_format(round(max(0.0, min(100.0, $v)), 2), 2, '.', '');
    }
}
