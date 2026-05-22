<?php

namespace App\Services;

use App\Models\Client;
use App\Services\Degs\DegsClient;
use RuntimeException;


class BankIdService
{
    private const POLL_MAX      = 30;
    private const POLL_SLEEP_MS = 2000;

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
        $ssn      = trim((string) ($client->social_card_number ?? ''));
        $passport = trim((string) ($client->passport_series ?? ''))
                  . trim((string) ($client->passport_issued ?? ''));
        $dob      = $client->date_of_birth
                        ? \Carbon\Carbon::parse($client->date_of_birth)->format('d/m/Y')
                        : '';
        $name     = trim((string) ($client->name    ?? ''));
        $surname  = trim((string) ($client->surname ?? ''));

        if ($ssn === '' && $passport === '') {
            throw new RuntimeException(
                'Client #' . $client->id . ': BankID hetk e social_card_number kame passport_series+passport_issued'
            );
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElement('P001');
        $dom->appendChild($root);

        if ($ssn !== '') {
            $root->appendChild($dom->createElement('SocialCardNumber', $ssn));
        }
        if ($passport !== '') {
            $root->appendChild($dom->createElement('PassportNumber', htmlspecialchars($passport, ENT_XML1)));
        }
        if ($dob !== '') {
            $root->appendChild($dom->createElement('DateOfBirth', $dob));
        }
        if ($name !== '') {
            $root->appendChild($dom->createElement('FirstName', htmlspecialchars($name, ENT_XML1)));
        }
        if ($surname !== '') {
            $root->appendChild($dom->createElement('LastName', htmlspecialchars($surname, ENT_XML1)));
        }

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

        $stripped = trim(strip_tags($xml));
        if (preg_match('/\b([0-9]{13})\b/', $stripped, $m)) {
            return $m[1];
        }

        if (preg_match('/<(?:BankID|ClientID|ID|bankId|client_id)[^>]*>([0-9]{13})</', $xml, $m)) {
            return $m[1];
        }

        throw new RuntimeException(
            "BankID: 13-nish ID-n chi gtvyel pataskhanum. Raw:\n" . substr($xml, 0, 500)
        );
    }
}
