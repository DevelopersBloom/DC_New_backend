<?php

namespace App\Services;

use App\Models\Client;
use App\Services\Degs\DegsClient;
use RuntimeException;


class BankIdService
{
    private const POLL_MAX      = 30;
    private const POLL_SLEEP_MS = 2000;

    private const NS            = 'urn:cba-am:bankid';
    private const ORG_CODE      = '66100';
    private const BRANCH_CODE   = '00001';

    public function __construct(private DegsClient $degs) {}


    /**
     * ԿԲ-ից stanum e BankID-ə u pakhum DB-um:
     * Veradardzoum e 13-nish BankID string:
     *
     * @throws RuntimeException  ege CB-i hastsume chetoghi
     */
    public function fetchAndSave(Client $client, bool $dryRun = false): string
    {
        if ($client->bank_client_id && preg_match('/^[0-9]{13}$/', $client->bank_client_id)) {
            return $client->bank_client_id;
        }

        $xml = $this->buildP001Xml($client);
dd($xml);
        $result = $this->degs->sendP001($xml, $dryRun);
        if (! $result['ok']) {
            throw new RuntimeException('BankID P001 send failed: ' . ($result['error'] ?? 'unknown'));
        }

        if ($dryRun) {
            return '0000000000000';
        }

        $requestId = (int) $result['requestId'];
        if ($requestId === 0) {
            throw new RuntimeException('BankID P001: requestId returned empty response');
        }

        $bankId = $this->pollAndGet($requestId);

        $client->bank_client_id = $bankId;
        $client->save();

        return $bankId;
    }


    private function buildP001Xml(Client $client): string
    {
        $now = \Carbon\Carbon::now();

        $firstName    = trim((string) ($client->name         ?? ''));
        $lastName     = trim((string) ($client->surname      ?? ''));
//        $familyName   = trim((string) ($client->middle_name  ?? ''));
        $identityType = trim((string) ($client->document_type ?? ''));
        $identityNum  = trim((string) ($client->passport_series ?? ''));
        $ssn          = trim((string) ($client->social_card_number ?? ''));
        $dob          = $client->date_of_birth
                            ? \Carbon\Carbon::parse($client->date_of_birth)->format('d/m/Y')
                            : '';
        $gender       = match ($client->gender) {
                            'MALE'   => 'M',
                            'FEMALE' => 'F',
                            default  => '',
                        };
        $residency    = trim((string) ($client->residency_country ?? 'ARM'));
        $expiry       = $client->passport_validity
                            ? \Carbon\Carbon::parse($client->passport_validity)->format('d/m/Y')
                            : '';

        // Validate required fields
        if ($firstName === '') {
            throw new RuntimeException("Client #{$client->id}: BankID P001 - name (FirstName) petq e");
        }
        if ($lastName === '') {
            throw new RuntimeException("Client #{$client->id}: BankID P001 - surname (LastName) petq e");
        }
        if ($identityType === '') {
            throw new RuntimeException("Client #{$client->id}: BankID P001 - document_type (IdentityType) petq e");
        }
        if ($identityNum === '') {
            throw new RuntimeException("Client #{$client->id}: BankID P001 - passport_series (IdentityNumber) petq e");
        }
        if ($dob === '') {
            throw new RuntimeException("Client #{$client->id}: BankID P001 - date_of_birth petq e");
        }
        if ($gender === '') {
            throw new RuntimeException("Client #{$client->id}: BankID P001 - gender (M/F) petq e");
        }
        if ($residency === '') {
            throw new RuntimeException("Client #{$client->id}: BankID P001 - residency_country petq e");
        }

        $dom  = new \DOMDocument('1.0', 'UTF-8');

        // Root P001 with correct namespace
        $root = $dom->createElementNS(self::NS, 'P001');
        $dom->appendChild($root);

        // --- ReportHeader ---
        $header = $dom->createElement('ReportHeader');
        $header->appendChild($dom->createElement('OrganisationCode',       self::ORG_CODE));
        $header->appendChild($dom->createElement('OrganisationBranchCode', self::BRANCH_CODE));
        $header->appendChild($dom->createElement('OrganizationStatus',     '0'));
        $sendDt = $dom->createElement('SendDateTime');
        $sendDt->appendChild($dom->createElement('Date', $now->format('d/m/Y')));
        $sendDt->appendChild($dom->createElement('Time', $now->format('H:i:s')));
        $header->appendChild($sendDt);
        $root->appendChild($header);

        // --- PersonFP (ֆիզիկական անձ) ---
        // Element order is strict per XSD xs:sequence
        $person = $dom->createElement('PersonFP');

        $person->appendChild($dom->createElement('FirstName',  htmlspecialchars($firstName,  ENT_XML1)));
        $person->appendChild($dom->createElement('LastName',   htmlspecialchars($lastName,   ENT_XML1)));
//        if ($familyName !== '') {
//            $person->appendChild($dom->createElement('FamilyName', htmlspecialchars($familyName, ENT_XML1)));
//        }
        $person->appendChild($dom->createElement('IdentityType',   $identityType));
        $person->appendChild($dom->createElement('IdentityNumber', htmlspecialchars($identityNum, ENT_XML1)));
//        if ($expiry !== '') {
//            $person->appendChild($dom->createElement('IdentityDateOfExpiry', $expiry));
//        }
        if ($ssn !== '') {
            $person->appendChild($dom->createElement('SSN', $ssn));
        }
        $person->appendChild($dom->createElement('DateOfBirth',      $dob));
        $person->appendChild($dom->createElement('Gender',           $gender));
        $person->appendChild($dom->createElement('ResidencyCountry', $residency));

        $root->appendChild($person);

        return $dom->saveXML($dom->documentElement);
    }

    // ================================================================
    // Poll + GetResponse
    // ================================================================

    private function pollAndGet(int $requestId): string
    {
        for ($i = 0; $i < self::POLL_MAX; $i++) {
            usleep(self::POLL_SLEEP_MS * 1000);

            $check = $this->degs->isResponsePrepared($requestId);
            if (! $check['ok']) {
                throw new RuntimeException('IsResponsePrepared error: ' . ($check['error'] ?? ''));
            }

            if ($check['prepared']) {
                return $this->extractBankId($requestId);
            }
        }

        throw new RuntimeException(
            "BankID requestId={$requestId}: " . (self::POLL_MAX * self::POLL_SLEEP_MS / 1000) . " vayrkyannum pataskhan chi stvyel"
        );
    }

    private function extractBankId(int $requestId): string
    {
        $resp = $this->degs->getResponse($requestId);
        if (! $resp['ok']) {
            throw new RuntimeException('GetResponse error: ' . ($resp['error'] ?? ''));
        }

        $xml = trim((string) ($resp['result'] ?? ''));
        if ($xml === '') {
            throw new RuntimeException("BankID requestId={$requestId}: datark pataskhan");
        }

        \Log::debug('BankID raw response', ['requestId' => $requestId, 'xml' => $xml]);

        // DOMDocument reads encoding from the XML declaration — no manual conversion needed.
        $dom = new \DOMDocument();
        if (@$dom->loadXML($xml)) {
            foreach ($dom->getElementsByTagName('Info') as $info) {
                if (strtoupper($info->getAttribute('Type')) === 'ERROR') {
                    $code = $dom->getElementsByTagName('ErrorCode')->item(0)?->textContent ?? 'UNKNOWN';
                    $desc = $dom->getElementsByTagName('ErrorDescription')->item(0)?->textContent ?? '';
                    throw new RuntimeException("BankID error {$code}: {$desc}");
                }
            }

            $stripped = trim(strip_tags($dom->saveXML()));
            if (preg_match('/\b([0-9]{13})\b/', $stripped, $m)) {
                return $m[1];
            }
        }

        if (preg_match('/<(?:BankID|ClientID|ID|bankId|client_id)[^>]*>([0-9]{13})</', $xml, $m)) {
            return $m[1];
        }

        throw new RuntimeException(
            "BankID: 13-nish ID-n chi gtvyel pataskhanum. Raw:\n" . substr($xml, 0, 500)
        );
    }
}
