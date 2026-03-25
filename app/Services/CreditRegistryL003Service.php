<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Generates L003 XML for Credit Registry (CBA lnreg3).
 * Root: L003, namespace: urn:cba-am:lnreg3
 * Children: ReportHeader, CreditCode, DeleteReason
 */
class CreditRegistryL003Service
{
    private const NS = 'urn:cba-am:lnreg3';
    private const ORGANISATION_CODE = '66100';
    private const ORGANISATION_BRANCH_CODE = '0001';
    private const ORGANIZATION_STATUS = 1;

    public function generateL003Xml(int $contractId, string $deleteReason): string
    {
        $contract = Contract::find($contractId);

        if (!$contract) {
            throw new RuntimeException('Contract not found: ' . $contractId);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'L003');
        $dom->appendChild($root);

        $root->appendChild($this->createReportHeader($dom));

        // 2. CreditCode
        $creditCode = $dom->createElement('CreditCode');
        $creditCode->appendChild($dom->createTextNode($contract->num ?? (string) $contract->id));
        $root->appendChild($creditCode);

        // 3. DeleteReason (max length 512 per XSD)
//        $reason = mb_substr($deleteReason, 0, 512);
//        $deleteReasonEl = $dom->createElement('DeleteReason');
//        $deleteReasonEl->appendChild($dom->createTextNode($reason));
//        $root->appendChild($deleteReasonEl);

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
}
