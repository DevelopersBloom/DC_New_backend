<?php

namespace App\Services;

use RuntimeException;
use SoapClient;
use SoapFault;

class CreditRegistrySoapClient
{
    private SoapClient $client;

    private function boolish(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    public function __construct()
    {
        $config = config('credit_registry');

        if (empty($config['wsdl'])) {
            throw new RuntimeException('CREDIT_REGISTRY_WSDL is not configured.');
        }

        $options = [
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
            'connection_timeout' => (int) ($config['connection_timeout'] ?? 30),
        ];

        $ssl = [
            'verify_peer' => $this->boolish($config['verify_peer'] ?? null, true),
            'verify_peer_name' => $this->boolish($config['verify_peer_name'] ?? null, true),
            'allow_self_signed' => $this->boolish($config['allow_self_signed'] ?? null, false),
        ];

        if (! empty($config['ca_cert_path'])) {
            $ssl['cafile'] = $config['ca_cert_path'];
        }

        if (! empty($config['peer_name'])) {
            $ssl['peer_name'] = $config['peer_name'];
        }

        if (! empty($config['client_cert_path'])) {
            $options['local_cert'] = $config['client_cert_path'];
            if (! empty($config['client_cert_password'])) {
                $options['passphrase'] = $config['client_cert_password'];
            }
        }

        $options['stream_context'] = stream_context_create(['ssl' => $ssl]);

        $this->client = new SoapClient($config['wsdl'], $options);
    }

    /**
     * Send L001 document (DocType = L001) to CBA.
     */
    public function sendL001(string $xml, bool $isDelay = false): int
    {
        return $this->sendRequest(
            appName: config('credit_registry.app_name', 'LNREG3'),
            docType: 'L001',
            xml: $xml,
            isDelay: $isDelay
        );
    }

    /**
     * Generic SendRequest wrapper if later you need other DocTypes (e.g. L002).
     */
    public function sendRequest(string $appName, string $docType, string $xml, bool $isDelay = false): int
    {
        try {
            $result = $this->client->__soapCall('SendRequest', [[
                'AppName' => $appName,
                'DocType' => $docType,
                'IsDelay' => $isDelay,
                'xml' => $xml,
            ]]);

            if (! isset($result->SendRequestResult)) {
                throw new RuntimeException('SendRequestResult is missing in SOAP response.');
            }

            return (int) $result->SendRequestResult;
        } catch (SoapFault $e) {
            throw new RuntimeException('SendRequest failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function isResponsePrepared(int $requestId): bool
    {
        try {
            $result = $this->client->__soapCall('IsResponsePrepared', [[
                'requsetId' => $requestId,
            ]]);

            return (bool) ($result->IsResponsePreparedResult ?? false);
        } catch (SoapFault $e) {
            throw new RuntimeException('IsResponsePrepared failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getResponse(int $requestId): ?string
    {
        try {
            $result = $this->client->__soapCall('GetResponse', [[
                'requsetId' => $requestId,
            ]]);

            return $result->GetResponseResult ?? null;
        } catch (SoapFault $e) {
            throw new RuntimeException('GetResponse failed: ' . $e->getMessage(), 0, $e);
        }
    }
}

