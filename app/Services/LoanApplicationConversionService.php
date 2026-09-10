<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Item;
use App\Models\ItemRealEstate;
use App\Models\LoanApplication;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoanApplicationConversionService
{
    /**
     * Fields ClientRequest marks required for each client type. Kept in one
     * place so profileIsComplete() and the frontend "complete profile" step
     * agree on exactly what full KYC means.
     */
    private const REQUIRED_INDIVIDUAL_FIELDS = [
        'passport_series',
        'passport_validity',
        'passport_issued',
        'date_of_birth',
        'gender',
        'document_type',
        'residency_status',
    ];

    private const REQUIRED_LEGAL_FIELDS = [
        'company_name',
        'legal_form',
        'tax_number',
    ];

    /**
     * Returns the list of KYC fields still missing for this client, so the
     * frontend can render exactly what is missing. An empty array means the
     * profile is complete.
     *
     * @return string[]
     */
    public function profileIsComplete(Client $client): array
    {
        $required = $client->type === 'legal'
            ? self::REQUIRED_LEGAL_FIELDS
            : self::REQUIRED_INDIVIDUAL_FIELDS;

        $missing = [];
        foreach ($required as $field) {
            $value = $client->getAttribute($field);
            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Converts an approved application into a Contract shell and its real
     * items / item_real_estates rows.
     *
     * Guarded: throws unless the application is approved AND the client's KYC
     * profile is complete. Items require a non-null contract_id, so the
     * Contract shell is created first; the existing New Loan wizard still
     * owns interest rate, term and disbursement — this only seeds it.
     *
     * @throws RuntimeException
     */
    public function convert(LoanApplication $loanApplication): Contract
    {
        if ($loanApplication->status !== LoanApplication::STATUS_APPROVED) {
            throw new RuntimeException('Only approved applications can be converted.');
        }

        $client = $loanApplication->client()->firstOrFail();

        $missing = $this->profileIsComplete($client);
        if ($missing !== []) {
            throw new RuntimeException(
                'Client profile is incomplete: ' . implode(', ', $missing)
            );
        }

        $loanApplication->loadMissing(['items.realEstate', 'finalEstimate']);

        $seedAmount = (float) ($loanApplication->finalEstimate->estimated_amount ?? 0);
        $firstItem = $loanApplication->items->first();

        return DB::transaction(function () use ($loanApplication, $client, $seedAmount, $firstItem) {
            $contract = Contract::create([
                'client_id'        => $client->id,
                'user_id'          => Auth::id(),
                'pawnshop_id'      => $loanApplication->pawnshop_id,
                'category_id'      => $firstItem?->category_id,
                'estimated_amount' => $seedAmount,
                'provided_amount'  => $seedAmount,
                'loan_type'        => $loanApplication->loan_type,
                'status'           => Contract::STATUS_INITIAL,
                'date'             => now()->toDateString(),
                // Placeholder; the New Loan wizard sets the real term/deadline.
                'deadline'         => now()->toDateString(),
                'description'      => $loanApplication->comments,
            ]);

            foreach ($loanApplication->items as $applicationItem) {
                // items.contract_id was dropped in favour of the contract_item
                // pivot (see 2025_03_02 migrations); link via the relation below.
                $item = Item::create([
                    'category_id'    => $applicationItem->category_id,
                    'subcategory'    => $applicationItem->subcategory,
                    'model'          => $applicationItem->model,
                    'weight'         => $applicationItem->weight,
                    'clear_weight'   => $applicationItem->clear_weight,
                    'hallmark'       => $applicationItem->hallmark,
                    'car_make'       => $applicationItem->car_make,
                    'manufacture'    => $applicationItem->manufacture,
                    'power'          => $applicationItem->power,
                    'license_plate'  => $applicationItem->license_plate,
                    'color'          => $applicationItem->color,
                    'registration'   => $applicationItem->registration,
                    'identification' => $applicationItem->identification,
                    'ownership'      => $applicationItem->ownership,
                    'description'    => $applicationItem->description,
                    'provided_amount' => $loanApplication->items->count() === 1 ? $seedAmount : null,
                ]);

                $contract->items()->syncWithoutDetaching([$item->id]);

                if ($applicationItem->realEstate) {
                    ItemRealEstate::create([
                        'item_id'              => $item->id,
                        'certificate_number'   => $applicationItem->realEstate->certificate_number,
                        'certificate_password' => $applicationItem->realEstate->certificate_password,
                        'cadastral_code'       => $applicationItem->realEstate->cadastral_code,
                        'area_sqm'             => $applicationItem->realEstate->area_sqm,
                        'is_joint'             => $applicationItem->realEstate->is_joint,
                    ]);
                }
            }

            $loanApplication->update([
                'status'      => LoanApplication::STATUS_CONVERTED,
                'contract_id' => $contract->id,
            ]);

            return $contract;
        });
    }
}
