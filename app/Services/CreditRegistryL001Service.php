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
 */
class CreditRegistryL001Service
{
    private const NS = 'urn:cba-am:lnreg3';

    /** OrganisationCode: Վարկատու կազմակերպության գրանցման համարը */
    private const ORGANISATION_CODE = '66100';

    /** OrganisationBranchCode: Մասնաճյուղի կոդը */
    private const ORGANISATION_BRANCH_CODE = '0001';

    /** OrganizationStatus: Վարկատուի կարգավիճակը (1-4), 1 = Գործող է */
    private const ORGANIZATION_STATUS = 1;

    public function generateL001Xml(Contract $contract): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'L001');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', self::NS);
        $dom->appendChild($root);

        $root->appendChild($this->createReportHeader($dom));
        $root->appendChild($this->createCreditCode($dom, $contract));
        $root->appendChild($this->createLoanData($dom, $contract));

        return $dom->saveXML();
    }

    private function createReportHeader(DOMDocument $dom): DOMElement
    {
        $header = $dom->createElement('ReportHeader');

        $header->appendChild($dom->createElement('OrganisationCode', self::ORGANISATION_CODE));
        $header->appendChild($dom->createElement('OrganisationBranchCode', self::ORGANISATION_BRANCH_CODE));
        $header->appendChild($dom->createElement('OrganizationStatus', (string) self::ORGANIZATION_STATUS));

        $now = Carbon::now();
        $sendDateTime = $dom->createElement('SendDateTime');
        $sendDateTime->appendChild($dom->createElement('Date', $now->format('Y-m-d')));
        $sendDateTime->appendChild($dom->createElement('Time', $now->format('H:i:s')));
        $header->appendChild($sendDateTime);

        return $header;
    }

    private function createCreditCode(DOMDocument $dom, Contract $contract): DOMElement
    {
        $creditCode = $dom->createElement('CreditCode');
        // Minimal placeholder; expand when full CreditCode XSD is available
        $creditCode->appendChild($dom->createTextNode($contract->num ?? (string) $contract->id));
        return $creditCode;
    }

    private function createLoanData(DOMDocument $dom, Contract $contract): DOMElement
    {
        $contract->loadMissing(['client', 'currency']);
        $client = $contract->client;

        $loanData = $dom->createElement('LoanData');

        // 1. DebtorID = borrower identifier (social_card_number for individual, tax_number for legal)
        $debtorId = $client
            ? ($client->type === 'legal' ? ($client->tax_number ?? '') : ($client->social_card_number ?? ''))
            : '';
        $loanData->appendChild($dom->createElement('DebtorID', $this->escape($debtorId)));

        // 2. IsPE (optional) - whether person borrowed as individual entrepreneur; N when unknown
        $loanData->appendChild($dom->createElement('IsPE', 'N'));

        // 3. AffectionWithCreditor = Կապակցված է ընկերությանը, Y/N
        $affection = $client && $client->is_linked_to_company ? 'Y' : 'N';
        $loanData->appendChild($dom->createElement('AffectionWithCreditor', $affection));

        // 4. ContractType = Վարկային պայմանագրի տեսակը
        $loanData->appendChild($dom->createElement('ContractType', $this->escape($contract->contract_kind ?? '')));

        // 5. ContractNumber = Վարկային պայմանագրի համարը
        $loanData->appendChild($dom->createElement('ContractNumber', $this->escape($contract->num ?? (string) $contract->id)));

        // 6. ContractDate = Վարկային պայմանագրի կնքման ամսաթիվը
        $contractDate = $contract->date ? Carbon::parse($contract->date)->format('Y-m-d') : '';
        $loanData->appendChild($dom->createElement('ContractDate', $contractDate));

        // 7. RepaymentDate = Վարկի վերջնական մարման ամսաթիվը
        $repaymentDate = $contract->deadline ? Carbon::parse($contract->deadline)->format('Y-m-d') : '';
        $loanData->appendChild($dom->createElement('RepaymentDate', $repaymentDate));

        // 8. LoanType = Վարկի տեսակը
        $loanData->appendChild($dom->createElement('LoanType', $this->escape($contract->loan_type ?? '')));

        // 9. Currency = Վարկի արժույթը
        $currencyCode = $contract->currency ? $contract->currency->code : 'AMD';
        $loanData->appendChild($dom->createElement('Currency', $this->escape($currencyCode)));

        // 10. ContractAmount = Պայմանագրով նախատեսված վարկի գումարը
        $contractAmount = $contract->contract_amount ?? 0;
        $loanData->appendChild($dom->createElement('ContractAmount', $this->formatAmount($contractAmount)));

        // 11. ContractModifiedAmount = Վարկի մայր գումարի մնացորդը (remaining principal)
        $modifiedAmount = $contract->provided_amount ?? 0;
        $loanData->appendChild($dom->createElement('ContractModifiedAmount', $this->formatAmount($modifiedAmount)));

        // 12. AnnualInterestRate = Նոմինալ տոկոսադրույք
        $annualRate = $contract->interest_rate * 365 ?? 0;
        $loanData->appendChild($dom->createElement('AnnualInterestRate', $this->formatRate($annualRate)));

        // 13. ActualInterestRate = Էֆեկտիվ տոկոսադրույք
        $actualRate = $contract->effective_annual_rate ?? 0;
        $loanData->appendChild($dom->createElement('ActualInterestRate', $this->formatRate($actualRate)));

        // 14. InterestRateType = Վարկի անվանական տոկոսադրույքի տեսակը
        $loanData->appendChild($dom->createElement('InterestRateType', $this->escape($contract->interest_rate_type ?? '')));

        // 15. IsInterestSubsidy = always N
        $loanData->appendChild($dom->createElement('IsInterestSubsidy', 'N'));

//        // 16. ProvisionOfCredit = required, Y/N (source/program flag)
//        $loanData->appendChild($dom->createElement('ProvisionOfCredit', 'N'));
//
//        // 17. LoanUseField = required, loan use sector
//        $loanUseField = $client?->activity_field ?? '';
//        $loanData->appendChild($dom->createElement('LoanUseField', $this->escape($loanUseField)));

        // 18. LoanUseCountry = Վարկի օգտագործման երկիրը
        $loanUseCountry = $client?->country ?? 'AM';
        $loanData->appendChild($dom->createElement('LoanUseCountry', $this->escape($loanUseCountry)));

        // 19. LoanUseRegion = Վարկի օգտագործման մարզը
        $loanUseRegion = $client?->region_code ?? '';
        $loanData->appendChild($dom->createElement('LoanUseRegion', $this->escape($loanUseRegion)));

        return $loanData;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function formatAmount($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function formatRate($value): string
    {
        return number_format((float) $value, 10, '.', '');
    }
}
