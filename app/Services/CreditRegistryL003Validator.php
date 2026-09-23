<?php

namespace App\Services;

use App\Models\Contract;

/**
 * Validates a Contract (+ delete reason) against the CBA Credit Registry
 * L003 (ctDeleteMessage) message BEFORE the XML is generated and sent.
 */
class CreditRegistryL003Validator
{
    /**
     * @return string[] Human-readable (Armenian) problem descriptions.
     *                   Empty array = the contract is ready for L003.
     */
    public function validate(Contract $contract, string $reason): array
    {
        $errors = [];

        // CreditCode is derived from ContractDate (CreditRegistryCodeTrait::buildCreditCode).
        // Without it the code won't match the one originally sent with L001.
        if (empty($contract->date)) {
            $errors[] = 'ContractDate (պայմանագրի կնքման ամսաթիվ) բացակայում է';
        }

        // DeleteReason — max length 512 per XSD; CreditRegistryL003Service silently
        // truncates anything longer, which would hide part of the reason from CBA.
        if (mb_strlen($reason) > 512) {
            $errors[] = "DeleteReason-ը (\"{$reason}\") գերազանցում է առավելագույն 512 նիշը";
        }

        if (trim($reason) === '') {
            $errors[] = 'DeleteReason (ջնջման պատճառ) պարտադիր է։';
        }

        return $errors;
    }
}
