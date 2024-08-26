<?php

use App\Models\Contract;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $today = Carbon::today();
        $deadlineDate = Carbon::create(2024, 10, 1);

        // Select 50 random contracts with deadline before 01.10.2024 and status 'overdue'
        $contracts = DB::table('contracts')
            ->where('status', 'overdue')
            ->whereDate(DB::raw("STR_TO_DATE(deadline, '%d.%m.%Y')"), '<', $deadlineDate)
            ->inRandomOrder()
            ->limit(20)
            ->pluck('id');

        if ($contracts->isNotEmpty()) {
            foreach ($contracts as $contractId) {
                $contract = Contract::find($contractId);

                if ($contract) {
                    // Process payments
                    $payments = Payment::where('contract_id', $contractId)
                        ->where('status', 'initial')
                        ->whereDate(DB::raw("STR_TO_DATE(date, '%d.%m.%Y')"), '<=', $today)
                        ->get();

                    foreach ($payments as $payment) {
                        // Process payment
                        $paymentAmount = $payment->amount;
                        $payment->status = 'completed';
                        $payment->paid = $paymentAmount;
                        $payment->save();

                        // Update contract collected amount
                        $contract->collected += $paymentAmount;
                        $contract->left -= $paymentAmount;
                    }

                    // Check if there are no more initial payments left
                    $paymentsLeft = Payment::where('contract_id', $contractId)
                        ->where('status', 'initial')
                        ->exists();

                    if (!$paymentsLeft) {
                        $contract->status = 'completed';
                        $contract->close_date = $today->format('d.m.Y');
                    } else {
                        $contract->status = 'initial';
                    }

                    $contract->save();
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Implement reverse logic if needed
    }
};
