<?php

namespace App\Services;

use App\Models\Contract;
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
        $currencyCode = $contract->currency?->code;

        // Only include elements we can reliably derive from DB.
        $modified = [];

        if ($client) {
            $modified['AffectionWithCreditor'] = $client->is_linked_to_company ? 'Y' : 'N';
        }

        if (($contract->contract_kind ?? '') !== '') {
            $modified['ContractType'] = (string) $contract->contract_kind;
        }

        if ($currencyCode !== null && $currencyCode !== '') {
            $modified['Currency'] = (string) $currencyCode;
        }

        if ($contract->contract_amount !== null) {
            $modified['ContractAmount'] = $this->formatAmount($contract->contract_amount);
        }

        if ($contract->provided_amount !== null) {
            $modified['ContractModifiedAmount'] = $this->formatAmount($contract->provided_amount);
            $modified['PrincipalAmount'] = $this->formatAmount($contract->provided_amount);
        }

        if ($contract->interest_rate !== null) {
            // Keep consistent with your L001 logic (daily * 365 => annual).
            $modified['AnnualInterestRate'] = $this->formatRate(((float) $contract->interest_rate) * 365);
        }

        if ($contract->effective_annual_rate !== null) {
            $modified['ActualInterestRate'] = $this->formatRate((float) $contract->effective_annual_rate);
        }

        if (($contract->interest_rate_type ?? '') !== '') {
            $modified['InterestRateType'] = (string) $contract->interest_rate_type;
        }

        // Your L001 hardcodes N; keep same unless you store subsidy in DB.
        $modified['IsInterestSubsidy'] = 'N';

        if (($contract->date ?? '') !== '') {
            $grantingDate = $this->formatDate($contract->date);
            if ($grantingDate !== '') {
                $modified['GrantingDate'] = $grantingDate;
            }
        }

        if (($contract->deadline ?? '') !== '') {
            $repaymentDate = $this->formatDate($contract->deadline);
            if ($repaymentDate !== '') {
                $modified['RepaymentDate'] = $repaymentDate;
            }
        }

        if (($contract->status ?? '') !== '') {
            // XSD expects Y=active, N=repaid. Map your statuses conservatively.
            $status = (string) $contract->status;
            $modified['LoanStatus'] = in_array($status, ['completed'], true) ? 'N' : 'Y';
        }

        return $modified === [] ? [] : ['ModifiedData' => $modified];
    }

    /**
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

