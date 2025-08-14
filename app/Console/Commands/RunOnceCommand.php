<?php

namespace App\Console\Commands;

use App\Imports\ContractImport;
use App\Imports\ContractImportNew;
use App\Imports\ContractsImportNewData;
use App\Imports\DealsImport;
use App\Imports\ItemImport;
use App\Imports\PaymentImportNew;
use App\Imports\PaymentImportNewData;
use App\Services\ContractService;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class RunOnceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'run-once';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Started Executing');
        $contractFilePath = base_path('ImportContractNewData.xlsx');
        Excel::import(app(ContractsImportNewData::class), $contractFilePath);
        $this->info('Contracts Executed');

//        $this->info('Started items import');
//        $itemsFilePath = base_path('CarImport.xlsx');
//        Excel::import(app(ItemImport::class),$itemsFilePath);
//        $this->info('Items Executed');
//
        $this->info('Payment Executing');
        $paymentFilePath = base_path('ImportPaymentNewData.xlsx');
        $paymentService = app(PaymentService::class);
        Excel::import(new PaymentImportNewData($paymentService), $paymentFilePath);
        $this->info('Payments Executed');

        $this->info('Deals Executing');
        $paymentFilePath = base_path('ImportDeals.xlsx');
        Excel::import(new DealsImport(),$paymentFilePath);
        $this->info('Deals Executed');
//        $paymentFilePath1 = base_path('PaymentImport.xlsx');
//        Excel::import(new PaymentImportNew(), $paymentFilePath1);
//        $this->info('PaymentImport Executed');

//        $paymentFilePath2 = base_path('Personal_Graph_Input2.xlsx');
//        Excel::import(new PaymentImport, $paymentFilePath2);
//        $this->info('Personal_Graph_Input2 Executed');

//        $contracts = Contract::all();
//        foreach ($contracts as $contract){
//            if($contract->passport){
//                $client = Client::where('passport', $contract->passport)->first();
//                if(!$client){
//                    $client = Client::where('name', $contract->name)
//                        ->where('surname',$contract->surname)
//                        ->where('dob',$contract->dob)->first();
//                }
//                if(!$client){
//                    $client = Client::create([
//                        'name' => $contract->name,
//                        'surname' => $contract->surname,
//                        'middle_name' => $contract->middle_name,
//                        'address' => $contract->address,
//                        'passport' => $contract->passport,
//                        'email' => $contract->email,
//                        'bank' => $contract->bank,
//                        'card' => $contract->card,
//                        'pawnshop_id' => 1,
//                        'phone1' => $contract->phone1,
//                        'phone2' => $contract->phone2,
//                        'dob' => $contract->dob,
//                        'passport_given' => $contract->passport_given
//                    ]);
//                }
//                $contract->client_id = $client->id;
//                $contract->save();
//            }else{
//                $client = Client::where('name', $contract->name)
//                    ->where('surname',$contract->surname)
//                    ->where('dob',$contract->dob)->first();
//                if(!$client){
//                    $client = Client::create([
//                        'name' => $contract->name,
//                        'surname' => $contract->surname,
//                        'middle_name' => $contract->middle_name,
//                        'address' => $contract->address,
//                        'passport' => $contract->passport,
//                        'email' => $contract->email,
//                        'bank' => $contract->bank,
//                        'card' => $contract->card,
//                        'pawnshop_id' => 1,
//                        'phone1' => $contract->phone1,
//                        'phone2' => $contract->phone2,
//                        'dob' => $contract->dob,
//                        'passport_given' => $contract->passport_given
//                    ]);
//                }
//                $contract->client_id = $client->id;
//                $contract->save();
//            }
//        }
//        $this->info('Clients Created');
//
//        $dealsPath1 = base_path('CashRegister1.xlsx');
//        Excel::import(new DealImport, $dealsPath1);
//        $this->info('Deals1 Executed');
//
//        $dealsPath2 = base_path('CashRegister2.xlsx');
//        Excel::import(new DealImport, $dealsPath2);
//        $this->info('Deals2 Executed');
    }
}
