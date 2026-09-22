<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Modification;

/**
 * Validates a Contract against the CBA Credit Registry L002 (ctModificator)
 * message BEFORE the XML is generated and sent, mirroring
 * CreditRegistryL001Validator: collect every problem instead of failing
 * silently (CreditRegistryL002Service currently drops unknown field codes
 * and empty values without complaint).
 */
class CreditRegistryL002Validator
{
    public function __construct(private CreditRegistryL002Service $l002Service)
    {
    }

    /**
     * @return string[] Human-readable (Armenian) problem descriptions.
     *                   Empty array = the contract is ready for L002.
     */
    public function validate(Contract $contract): array
    {
        $errors = [];

        // CreditCode is derived from ContractDate (CreditRegistryCodeTrait::buildCreditCode).
        // Without it the code won't match the one originally sent with L001.
        if (empty($contract->date)) {
            $errors[] = 'ContractDate (պայմանագրի կնքման ամսաթիվ) բացակայում է';
        }

        $mods = Modification::query()
            ->where('subject_type', Contract::class)
            ->where('subject_id', $contract->id)
            ->where('modification_type', 'Modificator')
            ->where('is_sent', false)
            ->get();

        $unknown = [];
        $missingValue = [];

        foreach ($mods as $mod) {
            if (!in_array($mod->field_code, CreditRegistryL002Service::MODIFIED_DATA_ALLOWED_FIELDS, true)) {
                $unknown[] = $mod->field_code;
                continue;
            }

            if ($mod->new_value === null || $mod->new_value === '') {
                $missingValue[] = $mod->field_code;
            }
        }

        if ($unknown !== []) {
            $errors[] = 'Հետևյալ դաշտերը (field_code) L002-ի թույլատրված ցանկում չեն և ուղարկելիս անտեսվելու են. ' . implode(', ', array_unique($unknown)) . '։';
        }

        if ($missingValue !== []) {
            $errors[] = 'Հետևյալ փոփոխությունների նոր արժեքը (new_value) բացակայում է. ' . implode(', ', array_unique($missingValue)) . '։';
        }

        if ($mods->isEmpty()) {
            $days = $this->l002Service->calculateOverdueDays($contract);
            $percent = $this->l002Service->calculateOverdueValues($contract, $days);

            $hasOverdueData = $days != 0 || ($percent['interest_payment'] ?? 0) != 0 || ($percent['principal_payment'] ?? 0) != 0;

            if (!$hasOverdueData) {
                $errors[] = 'Նոր փոփոխություններ չկան';
            }
        }

        return $errors;
    }
}
