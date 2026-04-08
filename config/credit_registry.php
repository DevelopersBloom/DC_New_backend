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
    'wsdl_local_path' => env('CREDIT_REGISTRY_WSDL_LOCAL_PATH'),
    // Optional override for SOAP service endpoint (useful when WSDL is served elsewhere)
    'endpoint' => env('CREDIT_REGISTRY_ENDPOINT'),

    // SOAP protocol version: "1.2" (recommended) or "1.1"
    'soap_version' => env('CREDIT_REGISTRY_SOAP_VERSION', '1.2'),

    // Optional client certificate (PEM/PFX converted to PEM) and password
    'client_cert_path' => env('CREDIT_REGISTRY_CLIENT_CERT_PATH'),
    'client_cert_password' => env('CREDIT_REGISTRY_CLIENT_CERT_PASSWORD'),

    // Optional Root / CA certificate for server validation
    'ca_cert_path' => env('CREDIT_REGISTRY_CA_CERT_PATH'),

    /*
     * SSL verification toggles.
     *
     * Prefer keeping verification enabled and configuring CA/peer_name correctly.
     * Only disable verification temporarily for troubleshooting.
     */
    'verify_peer' => false,
    'verify_peer_name' => false,
    'allow_self_signed' => true,
    // Optional peer name to validate certificate against (useful when connecting by IP)
    'peer_name' => env('CREDIT_REGISTRY_PEER_NAME'),

    // Default application name for LNREG3 documents
    'app_name' => env('CREDIT_REGISTRY_APP_NAME', 'LNREG3'),

    // Default timeout settings (seconds)
    'connection_timeout' => env('CREDIT_REGISTRY_CONNECTION_TIMEOUT', 30),
    'response_timeout' => env('CREDIT_REGISTRY_RESPONSE_TIMEOUT', 180),
];

