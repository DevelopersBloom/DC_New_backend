<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Client extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'type', // individual | legal

        'name',
        'surname',
        'middle_name',
        'passport_series',
        'passport_validity',
        'passport_issued',
        'date_of_birth',
        'social_card_number',
        'bank_client_id',
        'residency_status',
        'residency_country',

        'company_name',
        'legal_form',
        'tax_number',
        'state_register_number',
        'activity_field',
        'director_name',
        'accountant_info',
        'internal_code',

        'email',
        'phone',
        'additional_phone',
        'country',
        'city',
        'street',
        'building',
        'website',

        'bank_name',
        'account_number',
        'card_number',
        'iban',
        'swift_code',

        'has_contract',
        'date',
        'classification_id',
        'acra_classification_id',
        'is_linked_to_company',
        'is_company_employee',
        'status',
        'region_code',

        'gender',
        'document_type',
        'is_married',

        'legal_country', 'legal_province', 'legal_community',
        'legal_settlement', 'legal_street_building', 'legal_zip_code',

        'actual_country', 'actual_province', 'actual_community',
        'actual_settlement', 'actual_street_building', 'actual_zip_code',

    ];
    protected $casts = [
        'has_contract'      => 'boolean',
        'date'              => 'date:d-m-Y',
        'passport_validity' => 'date:d-m-Y',
        'date_of_birth'     => 'date:d-m-Y',
        'is_linked_to_company' => 'boolean',
        'is_company_employee'  => 'boolean',
        'is_married'           => 'boolean',
    ];


    protected static function boot()
    {
        parent::boot();
        static::created(function ($client) {
            $pawnshopId = auth()->user()->pawnshop_id ?? 1;
            if ($pawnshopId) {
                ClientPawnshop::firstOrCreate([
                    'client_id' => $client->id,
                    'pawnshop_id' => $pawnshopId,
                ]);
            }
        });
        static::updated(function ($client) {
            $pawnshopId = auth()->user()->pawnshop_id ?? 1;
            if ($pawnshopId) {
                ClientPawnshop::firstOrCreate([
                    'client_id' => $client->id,
                    'pawnshop_id' => $pawnshopId,
                ]);
            }
        });
    }

    public function pawnshopClients(): HasMany
    {
        return $this->hasMany(ClientPawnshop::class, 'client_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function completedContracts(){
        return $this->contracts()->where('status','completed');
    }

    public function activeContracts(){
        return $this->contracts()->where('status','initial');
    }
    public function sales(): HasMany
    {
        return $this->hasMany(Contract::class, 'seller_id');
    }
    public function pledgedContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'pledgee_id');
    }
    public function files(){
        return $this->morphMany(File::class,'fileable');
    }

    public function allFiles(): HasMany
    {
        return $this->hasMany(File::class, 'client_id');
    }
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
    public function scopeFilterByClient($query, $filters)
    {
        if (!empty($filters['id'])) {
            $query->whereRaw("CAST(id AS CHAR) LIKE ?", [$filters['id'] . '%']);
        }

        if (!empty($filters['name'])) {
            $query->where('name', 'LIKE', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['surname'])) {
            $query->where('surname', 'LIKE', '%' . $filters['surname'] . '%');
        }

        if (!empty($filters['patronymic'])) {
            $query->where('middle_name', 'LIKE', '%' . $filters['patronymic'] . '%');
        }

        if (!empty($filters['passport'])) {
            $query->where('passport_series', 'LIKE', '%' . $filters['passport'] . '%');
        }

        if (!empty($filters['phone'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('phone', 'LIKE', '%' . $filters['phone'] . '%')
                    ->orWhere('additional_phone', 'LIKE', '%' . $filters['phone'] . '%');
            });
        }
        if (!empty($filters['start_date'])) {
            $filters['start_date'] = Carbon::parse($filters['start_date'])->format('Y-m-d');
        }

        if (!empty($filters['end_date'])) {
            $filters['end_date'] = Carbon::parse($filters['end_date'])->format('Y-m-d');
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('date', [$filters['start_date'], $filters['end_date']]);
        } elseif (!empty($filters['start_date'])) {
            $query->where('date', '>=', $filters['start_date']);
        } elseif (!empty($filters['end_date'])) {
            $query->where('date', '<=', $filters['end_date']);
        }
        return $query;
    }
    public function getCodeAttribute()
    {
        return $this->type === 'individual'
            ? ($this->social_card_number ?? null)
            : ($this->tax_number ?? null);
    }

    public function getDisplayNameAttribute()
    {
        return $this->type === 'legal'
            ? ($this->company_name ?? '')
            : trim(($this->name ?? '') . ' ' . ($this->surname ?? ''));
    }
    public function classification(): BelongsTo
    {
        return $this->belongsTo(ClientClassification::class, 'classification_id');
    }
    public function acraClassification(): BelongsTo
    {
        return $this->belongsTo(ClientClassification::class, 'acra_classification_id');
    }

    public function guaranteedContracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_guarantors');
    }

}
