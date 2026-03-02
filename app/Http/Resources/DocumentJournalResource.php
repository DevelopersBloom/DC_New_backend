<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentJournalResource extends JsonResource
{
    public function toArray($request): array|\JsonSerializable|Arrayable
    {
        return [
            'id' => $this->id,
            'amount_amd' => $this->amount_amd,
            'debit_account_code' => $this->debitAccount->code ?? null,
            'credit_account_code' => $this->creditAccount->code ?? null,
            'comment' => $this->comment,
            'document_type' => $this->document_type,
        ];
    }
}
