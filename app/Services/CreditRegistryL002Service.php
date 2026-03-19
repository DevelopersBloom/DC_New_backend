<?php

namespace App\Services;

use App\Models\Contract;
use App\Traits\ContractTrait;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generates L002 XML for Credit Registry (CBA lnreg3).
 * Root: L002, namespace: urn:cba-am:lnreg3
 * Children order: ReportHeader, CreditCode, ModificationDateTime, DataToDelete?, DataToModify?
 */
class CreditRegistryL002Service
{
    use ContractTrait;
    private const NS = 'urn:cba-am:lnreg3';

    /**
     * Allowed <choice> element names inside ctLoanDataModify/ModifiedData.
     * Keeping this whitelist prevents generating schema-invalid XML.
     */
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
        'WithdrawalAmount',
    ];
    /** OrganisationCode: Վարկատու կազմակերպության գրանցման համարը */
    private const ORGANISATION_CODE = '66100';

    /** OrganisationBranchCode: Մասնաճյուղի կոդը */
    private const ORGANISATION_BRANCH_CODE = '0001';

    /** OrganizationStatus: Վարկատուի կարգավիճակը (1-4), 1 = Գործող է */
    private const ORGANIZATION_STATUS = 1;

    /**
     * Business rule: L002 is generated ONLY from DB state by Contract ID.
     * No DataToModify/DataToDelete payload comes from frontend.
     */
    public function generateL002Xml(int $contractId): string
    {
        $contract = Contract::query()
            ->with(['client', 'currency'])
            ->find($contractId);

        if (! $contract) {
            throw new RuntimeException('Contract not found: ' . $contractId);
        }

        $dataToModify = $this->buildDataToModifyFromContract($contract);
        $dataToDelete = $this->buildDataToDeleteFromContract($contract);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'L002');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', self::NS);
        $dom->appendChild($root);

        // Strict XSD order
        $root->appendChild($this->createReportHeader($dom));
        $root->appendChild($this->createCreditCode($dom, $contract));
        $root->appendChild($this->createModificationDateTime($dom));

        $dataToDeleteEl = $this->createDataToDelete($dom, $dataToDelete);
        if ($dataToDeleteEl !== null) {
            $root->appendChild($dataToDeleteEl);
        }

        $dataToModifyEl = $this->createDataToModify($dom, $dataToModify);
        if ($dataToModifyEl !== null) {
            $root->appendChild($dataToModifyEl);
        }

        return $dom->saveXML();
    }

    private function createReportHeader(DOMDocument $dom): DOMElement
    {
        $header = $dom->createElement('ReportHeader');

        $header->appendChild($this->createElementWithText($dom, 'OrganisationCode', self::ORGANISATION_CODE));
        $header->appendChild($this->createElementWithText($dom, 'OrganisationBranchCode', self::ORGANISATION_BRANCH_CODE));
        $header->appendChild($this->createElementWithText($dom, 'OrganizationStatus', (string) self::ORGANIZATION_STATUS));

        $now = Carbon::now();
        $sendDateTime = $dom->createElement('SendDateTime');
        $sendDateTime->appendChild($this->createElementWithText($dom, 'Date', $now->format('Y-m-d')));
        $sendDateTime->appendChild($this->createElementWithText($dom, 'Time', $now->format('H:i:s')));
        $header->appendChild($sendDateTime);

        return $header;
    }

    private function createCreditCode(DOMDocument $dom, Contract $contract): DOMElement
    {
        $creditCode = $dom->createElement('CreditCode');
        // Keep same behavior as L001 until CreditCode XSD is fully implemented
        $creditCode->appendChild($dom->createTextNode($contract->num ?? (string) $contract->id));
        return $creditCode;
    }

    private function createModificationDateTime(DOMDocument $dom): DOMElement
    {
        $now = Carbon::now();

        $modificationDateTime = $dom->createElement('ModificationDateTime');
        $modificationDateTime->appendChild($this->createElementWithText($dom, 'Date', $now->format('Y-m-d')));
        $modificationDateTime->appendChild($this->createElementWithText($dom, 'Time', $now->format('H:i:s')));

        return $modificationDateTime;
    }

    /**
     * ctLoanDataDelete sequence:
     * Persons?, Pawns?, Collaterals?, DeletedData?
     */
    private function createDataToDelete(DOMDocument $dom, array $data): ?DOMElement
    {
        $persons = $data['Persons'] ?? null;
        $pawns = $data['Pawns'] ?? null;
        $collaterals = $data['Collaterals'] ?? null;
        $deletedData = $data['DeletedData'] ?? null;

        $hasAny =
            $this->hasNonEmpty($persons) ||
            $this->hasNonEmpty($pawns) ||
            $this->hasNonEmpty($collaterals) ||
            $this->hasNonEmpty($deletedData);

        if (! $hasAny) {
            return null;
        }

        $dataToDeleteEl = $dom->createElement('DataToDelete');

        if ($this->hasNonEmpty($persons)) {
            $dataToDeleteEl->appendChild($this->createSectionElement($dom, 'Persons', $persons));
        }
        if ($this->hasNonEmpty($pawns)) {
            $dataToDeleteEl->appendChild($this->createSectionElement($dom, 'Pawns', $pawns));
        }
        if ($this->hasNonEmpty($collaterals)) {
            $dataToDeleteEl->appendChild($this->createSectionElement($dom, 'Collaterals', $collaterals));
        }

        if ($this->hasNonEmpty($deletedData)) {
            if (! is_array($deletedData)) {
                throw new InvalidArgumentException('DataToDelete.DeletedData must be an array of field names.');
            }

            $deletedDataEl = $dom->createElement('DeletedData');
            foreach ($deletedData as $fieldName) {
                if (! is_string($fieldName) || $fieldName === '') {
                    throw new InvalidArgumentException('DataToDelete.DeletedData must contain non-empty field names.');
                }
                $this->assertValidElementName($fieldName);
                $deletedDataEl->appendChild($dom->createElement($fieldName));
            }
            $dataToDeleteEl->appendChild($deletedDataEl);
        }

        return $dataToDeleteEl;
    }

    /**
     * ctLoanDataModify sequence:
     * Persons?, Pawns?, Collaterals?, ModifiedData*
     *
     * IMPORTANT: ModifiedData is a repeating container of exactly one chosen field per node.
     */
    private function createDataToModify(DOMDocument $dom, array $data): ?DOMElement
    {
        $persons = $data['Persons'] ?? null;
        $pawns = $data['Pawns'] ?? null;
        $collaterals = $data['Collaterals'] ?? null;

        // Preferred input shape:
        // ['ModifiedData' => ['ContractAmount' => '50000', 'Currency' => 'USD']]
        // Backward-compatible shape:
        // ['ContractAmount' => '50000', 'Currency' => 'USD']
        $modifiedDataPayload = $data['ModifiedData'] ?? null;
        $modifiedFields = [];
        if ($this->hasNonEmpty($modifiedDataPayload)) {
            if (! is_array($modifiedDataPayload)) {
                throw new InvalidArgumentException('DataToModify.ModifiedData must be an array.');
            }
            $modifiedFields = $this->normalizeModifiedDataPayload($modifiedDataPayload);
        } else {
            $modifiedFields = $data;
            unset($modifiedFields['Persons'], $modifiedFields['Pawns'], $modifiedFields['Collaterals'], $modifiedFields['ModifiedData']);
        }

        $hasAny =
            $this->hasNonEmpty($persons) ||
            $this->hasNonEmpty($pawns) ||
            $this->hasNonEmpty($collaterals) ||
            $this->hasNonEmpty($modifiedFields);

        if (! $hasAny) {
            return null;
        }

        $dataToModifyEl = $dom->createElement('DataToModify');

        if ($this->hasNonEmpty($persons)) {
            $dataToModifyEl->appendChild($this->createSectionElement($dom, 'Persons', $persons));
        }
        if ($this->hasNonEmpty($pawns)) {
            $dataToModifyEl->appendChild($this->createSectionElement($dom, 'Pawns', $pawns));
        }
        if ($this->hasNonEmpty($collaterals)) {
            $dataToModifyEl->appendChild($this->createSectionElement($dom, 'Collaterals', $collaterals));
        }

        $modifiedFields = $this->orderModifiedFieldsByXsd($modifiedFields);

        foreach ($modifiedFields as $fieldName => $value) {
            if (! is_string($fieldName) || $fieldName === '') {
                throw new InvalidArgumentException('DataToModify fields must be an associative array of fieldName => value.');
            }
            $this->assertValidElementName($fieldName);
            $this->assertAllowedModifiedDataField($fieldName);

            $modifiedDataEl = $dom->createElement('ModifiedData');
            $fieldEl = $dom->createElement($fieldName);
            $this->appendElementValue($dom, $fieldEl, $value);
            $modifiedDataEl->appendChild($fieldEl);
            $dataToModifyEl->appendChild($modifiedDataEl);
        }

        return $dataToModifyEl;
    }

    /**
     * Build schema-shaped DataToModify based on current Contract state.
     *
     * Returns:
     * [
     *   'Persons' => ... (optional, currently omitted),
     *   'Pawns' => ... (optional, currently omitted),
     *   'Collaterals' => ... (optional, currently omitted),
     *   'ModifiedData' => [ 'ContractAmount' => '...', 'Currency' => '...', ... ]
     * ]
     */
    private function buildDataToModifyFromContract(Contract $contract): array
    {
        $client = $contract->client;
        $modified = [];

        $modified['ActualInterestRate'] = $this->formatRate($contract->effective_annual_rate ?? 0);

        $modified['AffectionWithCreditor'] = ($client && $client->is_linked_to_company) ? 'Y' : 'N';

        $modified['AmountsOf'] = $this->formatAmount($contract->mother ?? 0);

        $modified['AmountsPaid'] = $this->formatAmount($contract->collected ?? 0);

        $modified['AnnualInterestRate'] = $this->formatRate(((float)($contract->interest_rate ?? 0)) * 365);

        //?
        $modified['CalculatedOtherObligations'] = $this->formatAmount($contract->calculated_other_obligations ?? 0);

        $penaltyAmount = $this->countPenalty($contract->id)['penalty_amount'] ?? 0;
        $modified['CalculatedPenalties'] = $this->formatAmount($penaltyAmount);

        $modified['ComissionPaid'] = $this->formatAmount($contract->commissions_paid ?? 0);

        $modified['ConditionsChangeCount'] = (int)($contract->modifications_count ?? 0);

        $modified['ContractAmount'] = $this->formatAmount($contract->contract_amount ?? 0);

        $modified['ContractModifiedAmount'] = $this->formatAmount($contract->modified_contract_amount ?? 0);

        $modified['ContractType'] = (string)($contract->contract_kind ?? '1');

        $modified['Currency'] = (string)($contract->currency?->code ?? 'AMD');

        $modified['GrantingDate'] = $this->formatDate($contract->date);

        $modified['InterestRateType'] = (string)($contract->interest_rate_type ?? '1');

        $modified['IsInterestSubsidy'] = ($contract->is_subsidized) ? 'Y' : 'N';


        $modified['LastClassificationDate'] = $this->formatDate($contract?->last_classification_date);

        $modified['LastExpirationDate'] = $this->formatDate($contract?->last_expiration_date);

        $modified['LoanStatus'] = in_array($contract->status, ['completed', 'closed'], true) ? 'N' : 'Y';

        $modified['Notes'] = $contract->notes ? mb_substr($contract->notes, 0, 500) : null;

        $modified['OverdueDays'] = (int)($contract->overdue_days ?? 0);

        $modified['OverduePercent'] = $this->formatAmount($contract->overdue_interest ?? 0);

        $modified['OverduePrincipalAmount'] = $this->formatAmount($contract->overdue_principal ?? 0);

        $modified['PenaltiesPaid'] = $this->formatAmount($contract->penalties_paid ?? 0);

        $modified['PercentsPaid'] = $this->formatAmount($contract->percents_paid ?? 0);

        $modified['PrincipalAmount'] = $this->formatAmount($contract->provided_amount ?? 0);

        $modified['RepaymentActualDate'] = $this->formatDate($contract->actual_repayment_date);

        // 28. RepaymentDate - Վերջնական մարման ամսաթիվը (ըստ պայմանագրի)
        $modified['RepaymentDate'] = $this->formatDate($contract->deadline);

        // 29. RepaymentSource - Վարկի մարման աղբյուրը
        $modified['RepaymentSource'] = (string)($contract->repayment_source ?? '1');

        // 30. RevisedDays - Վերանայված օրերի քանակը
        $modified['RevisedDays'] = (int)($contract->revised_days ?? 0);

        // 31. RevisionDate - Վերջին վերանայման ամսաթիվը
        $modified['RevisionDate'] = $this->formatDate($contract->revision_date);

        // 32. Risk - Վարկային ռիսկի դասը (0-7)
        $modified['Risk'] = (string)($contract->risk_class ?? '0');

        // 33. WithdrawalAmount - Մասհանման գումարը
        $modified['WithdrawalAmount'] = $this->formatAmount($contract->withdrawal_amount ?? 0);

        return ['ModifiedData' => $modified];
    }    /**
     * Build schema-shaped DataToDelete from DB state.
     * For now, we only generate DeletedData when we can confidently detect "cleared" values.
     * If nothing is to be deleted, returns [] which omits DataToDelete (minOccurs=0).
     */
    private function buildDataToDeleteFromContract(Contract $contract): array
    {
        // Placeholder: implement robust "cleared fields" detection when you have change tracking.
        // Business-safe default: don't emit DataToDelete unless explicitly needed.
        return [];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeModifiedDataPayload(array $payload): array
    {
        // Accept either:
        // 1) associative map: ['Currency' => 'USD', 'ContractAmount' => '50000']
        // 2) list of one-element maps: [ ['Currency' => 'USD'], ['ContractAmount' => '50000'] ]
        $isList = array_is_list($payload);

        if (! $isList) {
            /** @var array<string, scalar|null> $payload */
            return $payload;
        }

        $out = [];
        foreach ($payload as $item) {
            if (! is_array($item) || count($item) !== 1) {
                throw new InvalidArgumentException('DataToModify.ModifiedData list items must be one-element arrays.');
            }
            $k = (string) array_key_first($item);
            $out[$k] = $item[$k];
        }

        return $out;
    }

    private function createElementWithText(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $this->assertValidElementName($name);
        $el = $dom->createElement($name);
        $el->appendChild($dom->createTextNode($value));
        return $el;
    }

    /**
     * Append scalar or nested (array) value into an element.
     * - scalar|null => text node (or empty element if null)
     * - array => nested elements (future-proof for complex XSD types like ctAmountsPaid, etc.)
     */
    private function appendElementValue(DOMDocument $dom, DOMElement $el, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_int($k)) {
                    // list: either element names for empty tags, or one-element maps for nesting
                    if (is_string($v)) {
                        $this->assertValidElementName($v);
                        $el->appendChild($dom->createElement($v));
                        continue;
                    }
                    if (is_array($v) && count($v) === 1) {
                        $childName = (string) array_key_first($v);
                        $childEl = $dom->createElement($childName);
                        $this->appendElementValue($dom, $childEl, $v[$childName]);
                        $el->appendChild($childEl);
                        continue;
                    }

                    throw new InvalidArgumentException("Invalid nested list value for element {$el->tagName}.");
                }

                if (! is_string($k) || $k === '') {
                    throw new InvalidArgumentException("Invalid nested element name under {$el->tagName}.");
                }
                $this->assertValidElementName($k);
                $childEl = $dom->createElement($k);
                $this->appendElementValue($dom, $childEl, $v);
                $el->appendChild($childEl);
            }
            return;
        }

        $el->appendChild($dom->createTextNode((string) $value));
    }

    private function assertAllowedModifiedDataField(string $fieldName): void
    {
        if (! in_array($fieldName, self::MODIFIED_DATA_ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException("ModifiedData choice element is not allowed by XSD: {$fieldName}");
        }
    }

    /**
     * Ensures ModifiedData nodes are emitted in the exact XSD <choice> listing order.
     *
     * @param  array<string, mixed>  $modifiedFields
     * @return array<string, mixed>
     */
    private function orderModifiedFieldsByXsd(array $modifiedFields): array
    {
        if ($modifiedFields === []) {
            return [];
        }

        $ordered = [];

        // 1) fields in XSD order
        foreach (self::MODIFIED_DATA_ALLOWED_FIELDS as $fieldName) {
            if (array_key_exists($fieldName, $modifiedFields)) {
                $ordered[$fieldName] = $modifiedFields[$fieldName];
                unset($modifiedFields[$fieldName]);
            }
        }

        if ($modifiedFields !== []) {
            ksort($modifiedFields);
            foreach ($modifiedFields as $k => $v) {
                $ordered[$k] = $v;
            }
        }

        return $ordered;
    }

    private function formatAmount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function formatRate(mixed $value): string
    {
        return number_format((float) $value, 1, '.', '');
    }

    private function formatDate(mixed $value): string
    {
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function assertValidElementName(string $name): void
    {
        // XML Name production (simplified): start with letter/_ then allowed chars; disallow ":" (no prefixes here)
        if (str_contains($name, ':') || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name)) {
            throw new InvalidArgumentException("Invalid XML element name: {$name}");
        }
    }

    private function hasNonEmpty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return $value !== '';
        }

        return true;
    }

    /**
     * Generic nested section builder to keep L002 future-proof.
     * The caller must supply arrays already respecting the target XSD order.
     */
    private function createSectionElement(DOMDocument $dom, string $sectionName, mixed $payload): DOMElement
    {
        $this->assertValidElementName($sectionName);
        $sectionEl = $dom->createElement($sectionName);

        if ($payload === null) {
            return $sectionEl;
        }

        if (! is_array($payload)) {
            $sectionEl->appendChild($dom->createTextNode((string) $payload));
            return $sectionEl;
        }

        foreach ($payload as $key => $value) {
            if (is_int($key)) {
                // list items must be one-element maps: ['ElementName' => ...] or string element names for empty tags
                if (is_string($value)) {
                    $this->assertValidElementName($value);
                    $sectionEl->appendChild($dom->createElement($value));
                    continue;
                }
                if (is_array($value) && count($value) === 1) {
                    $childName = (string) array_key_first($value);
                    $sectionEl->appendChild($this->createSectionElement($dom, $childName, $value[$childName]));
                    continue;
                }

                throw new InvalidArgumentException("Invalid {$sectionName} list item structure.");
            }

            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException("Invalid {$sectionName} child element name.");
            }

            $sectionEl->appendChild($this->createSectionElement($dom, $key, $value));
        }

        return $sectionEl;
    }
}

