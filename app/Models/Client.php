<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Client extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'surname',
        'middle_name',
        'passport_series',
        'passport_validity',
        'passport_issued',
        'date_of_birth',
        'email',
        'phone',
        'additional_phone',
        'country',
        'city',
        'street',
        'building',
        'bank_name',
        'account_number',
        'card_number',
        'iban',
        'has_contract',
        'date',
        'pawnshop_id',
    ];
    protected $casts = [
        'date' => 'date',
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

    public function files(){
        return $this->morphMany(File::class,'fileable');
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


}
