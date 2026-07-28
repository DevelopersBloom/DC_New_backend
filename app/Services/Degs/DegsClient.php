<?php

namespace App\Services\Degs;

use App\Services\CreditRegistrySoapClient;
use Throwable;

/**
 * DegsClient — array-returning facade over CreditRegistrySoapClient.
 *
 * All public methods return ['ok' => bool, ...] so the controller
 * can handle errors uniformly without catching exceptions.
 */
class DegsClient
{
    public function __construct(private CreditRegistrySoapClient $soap) {}

    public function isAlive(): array
    {
        try {
            return ['ok' => true, 'alive' => $this->soap->isAlive()];
        } catch (Throwable $e) {
            return ['ok' => false, 'alive' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendL001(string $xml, bool $dryRun = false): array
    {
        try {
            return ['ok' => true, 'requestId' => $this->soap->sendL001($xml, $dryRun)];
        } catch (Throwable $e) {
            return ['ok' => false, 'requestId' => 0, 'error' => $e->getMessage()];
        }
    }

    public function sendL002(string $xml, bool $dryRun = false): array
    {
        try {
            return ['ok' => true, 'requestId' => $this->soap->sendL002($xml, $dryRun)];
        } catch (Throwable $e) {
            return ['ok' => false, 'requestId' => 0, 'error' => $e->getMessage()];
        }
    }

    public function sendL003(string $xml, bool $dryRun = false): array
    {
        try {
            return ['ok' => true, 'requestId' => $this->soap->sendL003($xml, $dryRun)];
        } catch (Throwable $e) {
            return ['ok' => false, 'requestId' => 0, 'error' => $e->getMessage()];
        }
    }

    public function sendL005(string $xml, bool $dryRun = false): array
    {
        try {
            return ['ok' => true, 'requestId' => $this->soap->sendL005($xml, $dryRun)];
        } catch (Throwable $e) {
            return ['ok' => false, 'requestId' => 0, 'error' => $e->getMessage()];
        }
    }

    public function sendL006(string $xml, bool $dryRun = false): array
    {
        try {
            return ['ok' => true, 'requestId' => $this->soap->sendL006($xml, $dryRun)];
        } catch (Throwable $e) {
            return ['ok' => false, 'requestId' => 0, 'error' => $e->getMessage()];
        }
    }

    public function sendP001(string $xml, bool $dryRun = false): array
    {
        try {
            return ['ok' => true, 'requestId' => $this->soap->sendBankIdP001($xml, $dryRun)];
        } catch (Throwable $e) {
            return ['ok' => false, 'requestId' => 0, 'error' => $e->getMessage()];
        }
    }

    public function isResponsePrepared(int $requestId): array
    {
        try {
            return ['ok' => true, 'prepared' => $this->soap->isResponsePrepared($requestId)];
        } catch (Throwable $e) {
            return ['ok' => false, 'prepared' => false, 'error' => $e->getMessage()];
        }
    }

    public function getResponse(int $requestId): array
    {
        try {
            return ['ok' => true, 'result' => $this->soap->getResponse($requestId)];
        } catch (Throwable $e) {
            return ['ok' => false, 'result' => '', 'error' => $e->getMessage()];
        }
    }
}
