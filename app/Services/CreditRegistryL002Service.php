<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Modification;
use App\Models\Payment;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

class CreditRegistryL002Service
{
    use CreditRegistryCodeTrait;
    private const NS = 'urn:cba-am:lnreg3';

    private const MODIFIED_DATA_ALLOWED_FIELDS = [
        'ActualInterestRate',
        'AffectionWithCreditor',
        'AmountsOf',
        'AmountsPaid',
        'AnnualInterestRate',
        'CalculatedOtherObligations',
        'CalculatedPenalties',
        'ComissionPaid',
        'ConditionsChangeCount',
        'ContractAmount',
        'ContractModifiedAmount',
        'ContractType',
        'Currency',
        'GrantingDate',
        'InterestRateType',
        'IsInterestSubsidy',
        'LastClassificationDate',
        'LastExpirationDate',
        'LoanStatus',
        'Notes',
        'OverdueDays',
        'OverduePercent',
        'OverduePrincipalAmount',
        'PenaltiesPaid',
        'PercentsPaid',
        'PrincipalAmount',
        'RepaymentActualDate',
        'RepaymentDate',
        'RepaymentSource',
        'RevisedDays',
        'RevisionDate',
        'Risk',
        'SecurityType',
        'WithdrawalAmount',
    ];

    private const ORGANISATION_CODE = '66100';
    private const ORGANISATION_BRANCH_CODE = '00001';
    private const ORGANIZATION_STATUS = 1;

    public function generateL002Xml(int $contractId): string
    {
        $contract = Contract::find($contractId);

        if (!$contract) {
            throw new RuntimeException('Contract not found');
        }

        $mods = Modification::query()
            ->where('subject_type', Contract::class)
            ->where('subject_id', $contractId)
            ->where('modification_type', 'Modificator')
            ->where('is_sent', false)
            ->get();

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'L002');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', self::NS);
        $dom->appendChild($root);

        $root->appendChild($this->createReportHeader($dom));
        $root->appendChild($this->createCreditCode($dom, $contract));
        $root->appendChild($this->createModificationDateTime($dom));

        $dataToModify = $this->createDataToModifyFromMods($dom, $mods,$contract);

        if ($dataToModify) {
            $root->appendChild($dataToModify);
        }

        return $dom->saveXML();
    }

    private function createDataToModifyFromMods(DOMDocument $dom, $mods, Contract $contract): ?DOMElement
    {
        $dataToModify = $dom->createElement('DataToModify');

        foreach ($mods as $mod) {

            $fieldName = $mod->field_code;

            if (!in_array($fieldName, self::MODIFIED_DATA_ALLOWED_FIELDS, true)) {
                continue;
            }

            $modifiedData = $dom->createElement('ModifiedData');

            $fieldEl = $this->createModificatorField($dom, $mod);

            $modifiedData->appendChild($fieldEl);
            $dataToModify->appendChild($modifiedData);
        }

        $days = $this->calculateOverdueDays($contract);
        $percent = $this->calculateOverdueValues($contract, $days);

        $runtimeFields = [
            'OverdueDays' => $days,
            'OverduePercent' => $percent['interest_payment'],
            'OverduePrincipalAmount' => $percent['principal_payment'],
        ];

        foreach ($runtimeFields as $field => $value) {

            if (!in_array($field, self::MODIFIED_DATA_ALLOWED_FIELDS, true)) {
                continue;
            }

            if ($value == 0) {
                continue;
            }

            $modifiedData = $dom->createElement('ModifiedData');

            $fieldEl = $dom->createElement($field);
            $fieldEl->appendChild(
                $dom->createElement('NewValue', (string)$value)
            );

            $modifiedData->appendChild($fieldEl);
            $dataToModify->appendChild($modifiedData);
        }

        return $dataToModify->hasChildNodes() ? $dataToModify : null;
    }
    /**
     * Universal ctModificator builder
     */
    private function createModificatorField(DOMDocument $dom, Modification $mod): DOMElement
    {
        $fieldEl = $dom->createElement($mod->field_code);

        if (!empty($mod->element_code) && false) {
            $inner = $dom->createElement($mod->element_code);

            if ($mod->old_value !== null) {
                $inner->appendChild($dom->createElement('OldValue', (string)$mod->old_value));
            }

            $inner->appendChild($dom->createElement('NewValue', (string)$mod->new_value));

            $fieldEl->appendChild($inner);

            return $fieldEl;
        }

        if ($mod->old_value !== null && $mod->old_value !== '') {
            $fieldEl->appendChild(
                $dom->createElement('OldValue', (string)$mod->old_value)
            );
        }

        $fieldEl->appendChild(
            $dom->createElement('NewValue', (string)$mod->new_value)
        );

        return $fieldEl;
    }

    private function createReportHeader(DOMDocument $dom): DOMElement
    {
        $header = $dom->createElement('ReportHeader');

        $header->appendChild($dom->createElement('OrganisationCode', self::ORGANISATION_CODE));
        $header->appendChild($dom->createElement('OrganisationBranchCode', self::ORGANISATION_BRANCH_CODE));
        $header->appendChild($dom->createElement('OrganizationStatus', self::ORGANIZATION_STATUS));

        $now = Carbon::now();

        $sendDateTime = $dom->createElement('SendDateTime');
        $sendDateTime->appendChild($dom->createElement('Date', $now->format('d/m/Y')));
        $sendDateTime->appendChild($dom->createElement('Time', $now->format('H:i:s')));

        $header->appendChild($sendDateTime);

        return $header;
    }

    private function createCreditCode(DOMDocument $dom, Contract $contract): DOMElement
    {
        return $dom->createElement('CreditCode', $this->buildCreditCode($contract));
    }

    private function createModificationDateTime(DOMDocument $dom): DOMElement
    {
        $now = Carbon::now();

        $el = $dom->createElement('ModificationDateTime');
        $el->appendChild($dom->createElement('Date', $now->format('d/m/Y')));
        $el->appendChild($dom->createElement('Time', $now->format('H:i:s')));

        return $el;
    }
    private function calculateOverdueDays(Contract $contract): int
    {
        $firstUnpaid = $contract->payments()
            ->where('status', '=', 'initial')
            ->orderBy('date')
            ->first();

        if (!$firstUnpaid) {
            return 0;
        }

        $today = now();
        $dueDate = Carbon::parse($firstUnpaid->date);

        if ($today->lessThanOrEqualTo($dueDate)) {
            return 0;
        }

        return $dueDate->diffInDays($today);
    }
    private function calculateOverdueValues(Contract $contract, int $days): array
    {
        if ($days <= 0) {
            return ['interest_payment' => 0, 'principal_payment' => 0];
        }
        $query = Payment::where('contract_id', $contract->id)
            ->where('status', 'initial')
            ->where('date', '<', now());

        if ($contract->payment_type == 'amortized') {
            $interestPayment = (clone $query)->sum('interest_payment');
            $principalPayment = (clone $query)->sum('principal_payment');
        } else {
            $interestPayment = (clone $query)->sum('amount');
            $principalPayment = 0;
        }
       return ['interest_payment' => $interestPayment, 'principal_payment' => $principalPayment];
    }
}
