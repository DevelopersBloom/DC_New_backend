<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credit Registry (CBA LNREG3) SOAP configuration
    |--------------------------------------------------------------------------
    |
    | All sensitive values come from .env so that you can later plug in the
    | real endpoints and certificates without code changes.
    |
    */

    // Full WSDL URL provided by CBA (e.g. https://host/DEGSHost?wsdl)
    'wsdl' => env('CREDIT_REGISTRY_WSDL'),
    // Optional local WSDL file path (use when remote ?wsdl is blocked/400)
    'wsdl_local_path' => storage_path('app/cba.wsdl'),
    // Optional override for SOAP service endpoint (useful when WSDL is served elsewhere)
    'endpoint' => env('CREDIT_REGISTRY_ENDPOINT'),

    // SOAP protocol version: "1.2" (recommended) or "1.1"
    'soap_version' => '1.2',
    'app_name' => 'LNREG3',
    // Client certificate PEM file (may be combined cert+key in one file)
    'client_cert_path'     => env('CREDIT_REGISTRY_CLIENT_CERT_PATH'),
    // Separate private key file — leave empty if cert+key are combined in one PEM
    'client_key_path'      => env('CREDIT_REGISTRY_CLIENT_KEY_PATH'),
    // Password for the private key (if encrypted)
    'client_cert_password' => env('CREDIT_REGISTRY_CLIENT_CERT_PASSWORD'),

    // CA / Root certificate for server validation (leave empty to skip CA check)
    'ca_cert_path' => env('CREDIT_REGISTRY_CA_CERT_PATH'),

    /*
     * SSL verification toggles.
     *
     * Prefer keeping verification enabled and configuring CA/peer_name correctly.
     * Only disable verification temporarily for troubleshooting.
     */
    'verify_peer' => env('CREDIT_REGISTRY_VERIFY_PEER', false),
    'verify_peer_name' => env('CREDIT_REGISTRY_VERIFY_PEER_NAME', false),
    'allow_self_signed' => env('CREDIT_REGISTRY_ALLOW_SELF_SIGNED', true),

    // Optional peer name to validate certificate against (useful when connecting by IP)
    'peer_name' => env('CREDIT_REGISTRY_PEER_NAME'),

    // .NET 8 DEGS proxy URL (127.0.0.1:5555 — runs as a local sidecar)
    'proxy_url' => env('CREDIT_REGISTRY_PROXY_URL', 'http://127.0.0.1:5555'),

    // Default timeout settings (seconds)
    'connection_timeout' => env('CREDIT_REGISTRY_CONNECTION_TIMEOUT', 30),
    'response_timeout' => env('CREDIT_REGISTRY_RESPONSE_TIMEOUT', 180),
    'password' => env('CREDIT_REGISTRY_PASSWORD'),
    'username' => 'test',
];

