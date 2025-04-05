<?php

namespace App\Exports;

use App\Models\Client;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class ClientsExport implements FromView
{
    protected $pawnshopId;

    public function __construct($pawnshopId)
    {
        $this->pawnshopId = $pawnshopId;
    }

    public function view(): View
    {
        $clients = Client::with('pawnshopClients')
            ->whereHas('pawnshopClients', function ($query) {
                $query->where('pawnshop_id', $this->pawnshopId);
            })
            ->get();

        return view('excel.clients', compact('clients'));
    }
}
