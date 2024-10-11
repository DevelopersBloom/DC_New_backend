<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentControllerNew extends Controller
{
    public function makePayment(Request $request)
    {
        DB::beginTransaction();
        try {
            $contract = Contract::findOrFail($request->contract_id);
            $amount = $request->amount;
            $penalty = $request->penalty ?? 0;
            //$payer = $request->payer;
            $cash = $request->cash;
            $paymentsSum = 0;

        } catch (\Exception $ex) {

        }
    }
}
