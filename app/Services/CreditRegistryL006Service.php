<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generates L006 XML for Credit Registry (CBA lnreg3).
 * L006 — Վարկ / Օգտագործման Ոլորտ կամ Նպատակ-ի ջնջում:
 *
 * Root: L006, namespace: urn:cba-am:lnreg3
 * Children: ReportHeader, CreditCode, DataToDelete
 * DataToDelete values: "LoanUseField" | "LoanUsePurpose"
 */
class CreditRegistryL006Service
{
    private const NS                       = 'urn:cba-am:lnreg3';
    private const ORGANISATION_CODE        = '66100';
    private const ORGANISATION_BRANCH_CODE = '0001';
    private const ORGANIZATION_STATUS      = 1;

    public const DELETE_LOAN_USE_FIELD   = 'LoanUseField';
    public const DELETE_LOAN_USE_PURPOSE = 'LoanUsePurpose';

    /**
     * @param  int    $contractId
     * @param  string $dataToDelete  'LoanUseField' or 'LoanUsePurpose'
     */
    public function generateL006Xml(int $contractId, string $dataToDelete): string
    {
        if (! in_array($dataToDelete, [self::DELETE_LOAN_USE_FIELD, self::DELETE_LOAN_USE_PURPOSE], true)) {
            throw new InvalidArgumentException(
                'dataToDelete must be "LoanUseField" or "LoanUsePurpose", got: ' . $dataToDelete
            );
        }

        $contract = Contract::find($contractId);
        if (! $contract) {
            throw new RuntimeException('Contract not found: ' . $contractId);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'L006');
        $dom->appendChild($root);

        $root->appendChild($this->createReportHeader($dom));
        $root->appendChild($this->createCreditCode($dom, $contract));
        $root->appendChild($dom->createElement('DataToDelete', $dataToDelete));

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
