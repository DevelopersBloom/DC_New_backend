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

    // Optional client certificate (PEM/PFX converted to PEM) and password
    'client_cert_path' => env('CREDIT_REGISTRY_CLIENT_CERT_PATH'),
    'client_cert_password' => env('CREDIT_REGISTRY_CLIENT_CERT_PASSWORD'),

    // Optional Root / CA certificate for server validation
    'ca_cert_path' => env('CREDIT_REGISTRY_CA_CERT_PATH'),

    // Default application name for LNREG3 documents
    'app_name' => env('CREDIT_REGISTRY_APP_NAME', 'LNREG3'),

    // Default timeout settings (seconds)
    'connection_timeout' => env('CREDIT_REGISTRY_CONNECTION_TIMEOUT', 30),
    'response_timeout' => env('CREDIT_REGISTRY_RESPONSE_TIMEOUT', 180),
];

