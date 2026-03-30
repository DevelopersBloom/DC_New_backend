<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractModification;
use App\Models\Modification;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Generates lnreg3 L002 XML with only ctLoanDataModify/ModifiedData/Risk based on a single modification row.
 * This is meant for "delta" exports driven by contract_modifications table.
 */
class CreditRegistryRiskModificationXmlService
{
    private const NS = 'urn:cba-am:lnreg3';

    /** OrganisationCode*/
    private const ORGANISATION_CODE = '66100';

    /** OrganisationBranchCode */
    private const ORGANISATION_BRANCH_CODE = '0001';

    /** OrganizationStatus */
    private const ORGANIZATION_STATUS = 1;

    public function generateCtRiskXml(Modification $mod): string
    {
        if (($mod->modification_type ?? null) !== 'RISK') {
            throw new RuntimeException('Expected modification_type=RISK, got: ' . ($mod->modification_type ?? 'null'));
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $risk = $this->createCtRiskElement($dom, $mod);
        $dom->appendChild($risk);

        return $dom->saveXML($risk);
    }

    public function generateL002RiskXml(Contract $contract, Modification $mod): string
    {
        if (($mod->modification_type ?? null) !== 'RISK') {
            throw new RuntimeException('Expected modification_type=RISK, got: ' . ($mod->modification_type ?? 'null'));
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'L002');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', self::NS);
        $dom->appendChild($root);

        $root->appendChild($this->createReportHeader($dom));
        $root->appendChild($this->createCreditCode($dom, $contract));
        $root->appendChild($this->createModificationDateTime($dom, $mod));

        $root->appendChild($this->createDataToModifyRisk($dom, $mod));

        return $dom->saveXML();
    }

    /**
     * Generates ONE XML file containing all unsent RISK modifications (ctRisk nodes).
     *
     * Output:
     * <RiskModifications xmlns="urn:cba-am:lnreg3">
     *   <Item>
     *     <ModificationId>...</ModificationId>
     *     <ContractId>...</ContractId>
     *     <CreditCode>...</CreditCode>
     *     <Risk>...</Risk>
     *   </Item>
     *   ...
     * </RiskModifications>
     *
     * NOTE: Inside <Risk> we follow ctRisk: OldValue? + NewValue.
     */
    public function generateUnsentRiskBatchXml(iterable $mods): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'RiskModifications');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', self::NS);
        $dom->appendChild($root);

        foreach ($mods as $mod) {
            if (!($mod instanceof Modification)) {
                continue;
            }
            if (($mod->modification_type ?? null) !== 'RISK') {
                continue;
            }

            $contractId = (int)($mod->subject_id ?? 0);
            if ($contractId <= 0) {
                continue;
            }

            $contract = Contract::query()->find($contractId);
            if (! $contract) {
                continue;
            }

            $item = $dom->createElement('Item');
            $item->appendChild($dom->createElement('ModificationId', (string)$mod->id));
            $item->appendChild($dom->createElement('ContractId', (string)$contract->id));

            $creditCode = $contract->num ?? (string)$contract->id;
            $item->appendChild($dom->createElement('CreditCode', $creditCode));

            $risk = $this->createCtRiskElement($dom, $mod);
            $item->appendChild($risk);

            $root->appendChild($item);
        }

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
        $creditCode->appendChild($dom->createTextNode($contract->num ?? (string) $contract->id));
        return $creditCode;
    }

    private function createModificationDateTime(DOMDocument $dom, Modification $mod): DOMElement
    {
        $dateTime = $mod->effective_date
            ? Carbon::parse($mod->effective_date)
            : Carbon::parse($mod->created_at ?? now());

        $el = $dom->createElement('ModificationDateTime');
        $el->appendChild($dom->createElement('Date', $dateTime->format('Y-m-d')));
        $el->appendChild($dom->createElement('Time', $dateTime->format('H:i:s')));
        return $el;
    }

    /**
     * Builds:
     * <DataToModify>
     *   <ModifiedData>
     *     <Risk>
     *       <OldValue>..</OldValue> (optional)
     *       <NewValue>..</NewValue>
     *     </Risk>
     *   </ModifiedData>
     * </DataToModify>
     */
    private function createDataToModifyRisk(DOMDocument $dom, Modification $mod): DOMElement
    {
        $dataToModify = $dom->createElement('DataToModify');
        $modifiedData = $dom->createElement('ModifiedData');

        $risk = $this->createCtRiskElement($dom, $mod);

        $modifiedData->appendChild($risk);
        $dataToModify->appendChild($modifiedData);

        return $dataToModify;
    }

    private function createCtRiskElement(DOMDocument $dom, Modification $mod): DOMElement
    {
        $risk = $dom->createElement('Risk');

        $old = $this->normalizeRiskValue($mod->old_value, true);
        if ($old !== null) {
            $risk->appendChild($dom->createElement('OldValue', (string) $old));
        }

        $new = $this->normalizeRiskValue($mod->new_value, false);
        $risk->appendChild($dom->createElement('NewValue', (string) $new));

        return $risk;
    }

    private function normalizeRiskValue(mixed $value, bool $allowNull): ?int
    {
        if ($value === null || $value === '') {
            return $allowNull ? null : 0;
        }

        $v = (int) $value;
        if ($v < 0) $v = 0;
        if ($v > 7) $v = 7;
        return $v;
    }
}

