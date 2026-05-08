<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generates L005 XML for Credit Registry (CBA lnreg3).
 * L005 — Վարկ / Օգտագործման Ոլորտ կամ Նպատակ-ի թարմացում:
 *
 * Root: L005, namespace: urn:cba-am:lnreg3
 * Children: ReportHeader, CreditCode, (LoanUseField | LoanUsePurpose)
 */
class CreditRegistryL005Service
{
    private const NS                    = 'urn:cba-am:lnreg3';
    private const ORGANISATION_CODE     = '66100';
    private const ORGANISATION_BRANCH_CODE = '0001';
    private const ORGANIZATION_STATUS   = 1;

    public const FIELD_LOAN_USE_FIELD   = 'LoanUseField';
    public const FIELD_LOAN_USE_PURPOSE = 'LoanUsePurpose';

    /**
     * @param  int    $contractId
     * @param  string $fieldType   'LoanUseField' or 'LoanUsePurpose'
     * @param  string $newValue    New value for the field
     * @param  string|null $oldValue Old value (include if changing from a known value)
     */
    public function generateL005Xml(
        int $contractId,
        string $fieldType,
        string $newValue,
        ?string $oldValue = null
    ): string {
        if (! in_array($fieldType, [self::FIELD_LOAN_USE_FIELD, self::FIELD_LOAN_USE_PURPOSE], true)) {
            throw new InvalidArgumentException(
                'fieldType must be "LoanUseField" or "LoanUsePurpose", got: ' . $fieldType
            );
        }

        $contract = Contract::find($contractId);
        if (! $contract) {
            throw new RuntimeException('Contract not found: ' . $contractId);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'L005');
        $dom->appendChild($root);

        $root->appendChild($this->createReportHeader($dom));
        $root->appendChild($this->createCreditCode($dom, $contract));
        $root->appendChild($this->createFieldElement($dom, $fieldType, $newValue, $oldValue));

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

    /**
     * CreditCode — same format as L001: NNNNN-YYYYMMDD-NNNNNX
     * Must match the code that was originally sent with L001.
     */
    private function createCreditCode(DOMDocument $dom, Contract $contract): DOMElement
    {
        $orgCode  = self::ORGANISATION_CODE;
        $datePart = Carbon::parse($contract->date)->format('Ymd');
        $sequence = str_pad(substr($contract->num, -5), 5, '0', STR_PAD_LEFT);
        $base     = $orgCode . $datePart . $sequence;
        $checksum = $this->calculateCbaChecksum($base);

        $formattedCode = sprintf('%s-%s-%s%d', $orgCode, $datePart, $sequence, $checksum);

        return $dom->createElement('CreditCode', $formattedCode);
    }

    private function createFieldElement(
        DOMDocument $dom,
        string $fieldType,
        string $newValue,
        ?string $oldValue
    ): DOMElement {
        $fieldEl = $dom->createElement($fieldType);

        if ($oldValue !== null && $oldValue !== '') {
            $fieldEl->appendChild($dom->createElement('OldValue', $oldValue));
        }

        $fieldEl->appendChild($dom->createElement('NewValue', $newValue));

        return $fieldEl;
    }

    /**
     * CBA Luhn Mod 10 checksum (identical to L001Service implementation).
     */
    private function calculateCbaChecksum(string $input): int
    {
        $digits = str_split($input);
        $sum    = 0;
        $len    = count($digits);

        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$i];
            // Positions 1, 3, 5 ... from right (0-indexed from right = even index from right)
            if (($len - 1 - $i) % 2 === 0) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
