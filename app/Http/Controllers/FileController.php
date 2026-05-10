<?php

namespace App\Http\Controllers;

use App\Exports\ContractsExport;
use App\Exports\DealsExport;
use App\Exports\PaymentsExport;
use App\Models\Contract;
use App\Models\Order;
use App\Services\ActivityService;
use App\Traits\CalculationTrait;
use App\Traits\FileTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;
use ZipStream\File;
use App\Models\File as ModelsFile;
class FileController extends Controller
{
    use CalculationTrait;
    protected ActivityService $activity;

    public function __construct(ActivityService $activity)
    {
        $this->activity = $activity;
    }
    public function index()
    {
        $files = ModelsFile::orderBy('created_at', 'desc')
            ->select('id', 'name', 'path', 'original_name')
            ->get();

        $files->transform(function ($file) {
            $file->url = asset('storage/' . $file->path);
            return $file;
        });

        return response()->json([
            'data' => $files
        ]);
    }

    public function download($id)
    {
        $file = ModelsFile::findOrFail($id);

        if (!$file->path || !Storage::disk('public')->exists($file->path)) {
            abort(404, 'File not found');
        }

        return Storage::disk('public')->download(
            $file->path,
            $file->original_name
        );
    }
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
            'name' => 'required|string|max:255',
            'fileable_id' => 'nullable|integer',
            'fileable_type' => 'nullable|string',
        ]);


        $uploadedFile = $request->file('file');

        $originalName = $uploadedFile->getClientOriginalName();

        $storedName = Str::uuid() . '.' . $uploadedFile->getClientOriginalExtension();

        $path = $uploadedFile->storeAs('files', $storedName, 'public');

        ModelsFile::create([
            'file_type' => $uploadedFile->getClientMimeType(),
            'fileable_id' => $request->fileable_id,
            'fileable_type' => $request->fileable_type,
            'name' => $request->name,
            'original_name' => $originalName,
            'type' => $uploadedFile->getClientOriginalExtension(),
            'doc_type' => $request->doc_type ?? 'regular',
            'path' => $path,
        ]);

        return response()->json([
            'message' => 'File uploaded successfully',
        ]);
    }

    public function destroy($id)
    {
        $file = ModelsFile::findOrFail($id);

        if ($file->path && Storage::disk('public')->exists($file->path)) {
            Storage::disk('public')->delete($file->path);
        }

        $file->delete();

        return response()->json([
            'message' => 'File deleted successfully'
        ]);
    }

    /**
     * @throws CopyFileException
     * @throws CreateTemporaryFileException
     */
    public function downloadContractGold($id)
    {
        $contract = Contract::with(['client', 'items.category', 'pawnshop', 'payments'])->findOrFail($id);

        $client = $contract->client;
        $user = $contract->user;
        $filesToZip = [];

        $templateProcessor = new TemplateProcessor(
            public_path('files/contract_gold_template.docx')
        );
        $clientName = $client->name . ' ' . $client->surname;
//        $clientName = $client->name . ' ' . $client->surname . ' ' . ($client->middle_name ?? '');
        $userName = $user->name . ' ' . $user->surname . ' ' . ($user->middle_name ?? '');
        $yearlyRate = round($contract->interest_rate * 365, 5);
        $effectiveRate = round($contract->effective_annual_rate, 5);

        $templateProcessor->setValues([
            'num' => $contract->num,
            'date' => \Carbon\Carbon::parse($contract->date)->format('d.m.Y'),

            'client' => $clientName,
            'passport_series' => $client->passport_series,
            'passport_validity' => \Carbon\Carbon::parse($client->passport_validity)->format('d.m.Y'),
            'passport_issued' => $client->passport_issued,
            'social_card_number' => $client->social_card_number ?? $client->tax_number ?? '',
            'city' => $client->city,
            'street' => $client->street,
            'phone' => $client->phone ?? $contract->additional_phone ?? '',

            'contract_amount' => $this->makeMoney((int)$contract->contract_amount),
            'mother_amount' => $this->makeMoney((int)$contract->estimated_amount),

            'interest_annual_rate' => $yearlyRate . ' %',
            'effective_annual_rate' => $effectiveRate . ' %',

            'deadline' => \Carbon\Carbon::parse($contract->deadline)->format('d.m.Y'),

            'bank_name' => $client->bank_name,
            'account_number' => $client->account_number,
            'card_number' => $client->card_number,
            'user_name' => $userName,
        ]);

        $paymentRows = [];
        foreach ($contract->payments as $p) {
            $paymentRows[] = [
                'p_d' => \Carbon\Carbon::parse($p->date)->format('d.m.Y'),
                'p_m' => $this->makeMoney((int)$p->principal_payment),
                'p_i' => $this->makeMoney((int)$p->interest_payment),
                'p_a' => $this->makeMoney((int)$p->amount),
                'p_r' => $this->makeMoney((int)$p->remaining),
            ];
        }

        if (count($paymentRows)) {
            $templateProcessor->cloneRowAndSetValues('p_d', $paymentRows);
        }
        $itemRows = [];

        $totalCount = 0;
        $totalWeight = 0;
        $totalClearWeight = 0;
        $totalAmount = 0;
        $totalSum = 0;

        foreach ($contract->items as $item) {
            $amount = $item->provided_amount ?? $contract->mother;
            $count  = $item->count ?? 1;

            $rowTotal = (int)$amount * (int)$count;

            $itemRows[] = [
                'i_desc'     => $item->category->title . ' ' . $item->subcategory,
                'i_c'        => $count,
                'i_w'        => $item->weight,
                'i_cw'       => $item->clear_weight,
                'i_h'        => $item->hallmark,
                'i_am'       => $amount,
                'i_total_am' => $rowTotal,
            ];

            $totalCount       += $count;
            $totalWeight      += (float)$item->weight;
            $totalClearWeight += (float)$item->clear_weight;
            $totalAmount      += (float)$amount;
            $totalSum         += $rowTotal;
        }
        $templateProcessor->setValues([
            't_i_c' => $totalCount,
            't_i_w' => $totalWeight,
            't_i_cw' => $totalClearWeight,
            't_i_am' => $totalAmount,
            't_i_total_am' => $totalSum,

        ]);
        if (count($itemRows)) {
            $templateProcessor->cloneRowAndSetValues('i_desc', $itemRows);
        }
        $contractFilename = $contract->num . '_ոսկու_պայմանագիր.docx';
        $contractPath = storage_path('app/tmp/' . $contractFilename);

        if (!file_exists(dirname($contractPath))) {
            mkdir(dirname($contractPath), 0775, true);
        }

        $templateProcessor->saveAs($contractPath);
        $filesToZip[] = $contractPath;


        $zipFileName = $contract->num . '_փաստաթղթեր.zip';
        $zipFilePath = storage_path('app/tmp/' . $zipFileName);

        $this->activity->log(
            'download_contract_file',
            'User downloaded contract file with num = #' . $contract->num,
            Contract::class,
            $contract->id,
        );

        $zip = new \ZipArchive;
        if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            foreach ($filesToZip as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        } else {
            abort(500, 'Չհաջողվեց ստեղծել ZIP ֆայլ։');
        }

        foreach ($filesToZip as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        return response()->download($zipFilePath, $zipFileName)->deleteFileAfterSend(true);
    }
    public function downloadContract($id)
    {
        $contract = Contract::with(['category', 'client', 'items.category', 'pawnshop', 'payments', 'user','seller','guarantors'])->findOrFail($id);

        $client = $contract->client;
        $seller = $contract->seller;
        $user = $contract->user;
        $filesToZip = [];

        $firstItem = $contract->items->first();
        $categoryName = $firstItem->category->name ?? 'gold';

        if ($categoryName == 'car') {
            $templateFileName = 'contract_car_template.docx';
        } elseif ($categoryName == 'gold') {
            $templateFileName = 'contract_gold_template.docx';
        } elseif ($categoryName == 'car-purchase') {
            $templateFileName = 'contract_buying_car_template.docx';
        }
        if (!$templateFileName) {
            return 'Template file not found';
        }
        $templatePath = public_path('files/' . $templateFileName);

        if (!file_exists($templatePath)) {
            abort(404, "Template not found: " . $templateFileName);
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        $clientName = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
        $userName = $user ? ($user->name . ' ' . $user->surname) : '';
        $sellerName = $seller ? ($seller->name . ' ' . $seller->surname) : '';
        $yearlyRate = round($contract->interest_rate * 365, 2);
        $effectiveRate = round($contract->effective_annual_rate, 2);
        $lumpRate = round($contract->lump_rate, 2);
        $sellerCode = $seller
            ? ($seller->type === 'individual'
                ? ($seller->social_card_number ?? '')
                : ($seller->tax_number ?? ''))
            : '';
        $templateProcessor->setValues([
            'num' => $contract->num,
            'date' => \Carbon\Carbon::parse($contract->date)->format('d.m.Y'),
            'client' => $clientName,
            'passport_series' => $client->passport_series,
            'passport_validity' => \Carbon\Carbon::parse($client->passport_validity)->format('d.m.Y'),
            'passport_issued' => $client->passport_issued,
            'social_card_number' => $client->social_card_number ?? $client->tax_number ?? '',
            'city' => $client->actual_province ?? $client->city,
            'street' => $client->actual_street_building ?? $client->street,
            'phone' => $client->phone ?? $contract->additional_phone ?? '',
            'contract_amount' => $this->makeMoney((int)$contract->contract_amount),
            'mother_amount' => $this->makeMoney((int)$contract->estimated_amount),
            'interest_annual_rate' => $yearlyRate . ' %',
            'effective_annual_rate' => $effectiveRate . ' %',
            'deadline' => \Carbon\Carbon::parse($contract->deadline)->format('d.m.Y'),
            'bank_name' => $client->bank_name,
            'account_number' => $client->account_number,
            'card_number' => $client->card_number,
            'user_name' => $userName,
            'lump_rate' => $lumpRate,
            'seller' => $sellerName,
            'seller_email' => $seller?->email,
            'seller_bank_name' => $seller?->bank_name,
            'seller_account_number' => $seller?->account_number,
            'seller_address' => $seller?->city . ',' . $seller?->street,
            'seller_code' => $sellerCode,
        ]);

        $paymentRows = [];
        foreach ($contract->payments as $p) {
            $paymentRows[] = [
                'p_d' => \Carbon\Carbon::parse($p->date)->format('d.m.Y'),
                'p_m' => $this->makeMoney((int)$p->principal_payment),
                'p_i' => $this->makeMoney((int)$p->interest_payment),
                'p_a' => $this->makeMoney((int)$p->amount),
                'p_r' => $this->makeMoney((int)$p->remaining),
            ];
        }
        if (!empty($paymentRows)) {
            $templateProcessor->cloneRowAndSetValues('p_d', $paymentRows);
        }

        if ($categoryName == 'car') {
            if ($firstItem) {
                $amount = $firstItem->provided_amount ?? $contract->mother;
                $templateProcessor->setValues([
                    'i_car_make' => $firstItem->car_make,
                    'i_mod'      => $firstItem->model,
                    'i_pow'      => $firstItem->power,
                    'i_man'      => $firstItem->manufacture,
                    'i_col'      => $firstItem->color,
                    'i_reg'      => $firstItem->registration,
                    'i_own'      => $firstItem->ownership,
                    'i_ident'    => $firstItem->identification,
                    'i_l_p'      => $firstItem->license_plate,
                    'i_est'      => $this->makeMoney((int)$contract->estimated_amount),
                    'i_prov'     => $this->makeMoney((int)$amount),
                    'i_desc'     => $contract->description ?? '',
                ]);
            }
        } else {
            $itemRows = [];
            $totals = ['count' => 0, 'weight' => 0, 'clear_weight' => 0, 'sum' => 0];

            foreach ($contract->items as $item) {
                $amount = $item->provided_amount ?? $contract->mother;
                $count = $item->count ?? 1;
                $rowTotal = (int)$amount * (int)$count;

                $itemRows[] = [
                    'i_desc'     => $item->category->title . ' ' . $item->subcategory,
                    'i_c'        => $count,
                    'i_w'        => $item->weight,
                    'i_cw'       => $item->clear_weight,
                    'i_h'        => $item->hallmark,
                    'i_am'       => $this->makeMoney((int)$amount),
//                    't_i_am' => $this->makeMoney((int)$rowTotal),
                ];

                $totals['count']  += $count;
                $totals['weight'] += (float)$item->weight;
                $totals['clear_weight'] += (float)$item->clear_weight;
                $totals['sum']    += $rowTotal;
            }

            if (!empty($itemRows)) {
                $templateProcessor->cloneRowAndSetValues('i_desc', $itemRows);
                $templateProcessor->setValues([
                    't_i_c' => $totals['count'],
                    't_i_w' => $totals['weight'],
                    't_i_cw' => $totals['clear_weight'],
                    't_i_am' => $this->makeMoney((int)$totals['sum']),
                ]);
            }
        }

        if ($categoryName == 'car') {
            $typeSuffix = 'մեքենայի';
        } elseif ($categoryName == 'gold') {
            $typeSuffix = 'ոսկու';
        } elseif ($categoryName == 'car-purchase') {
            $typeSuffix = 'մեքենայի ձեռք բերման';
        }

        if ($contract->guarantors && $contract->guarantors->count()) {

            foreach ($contract->guarantors as $guarantor) {
                [$guarantorFilePath] = $this->generateGuarantorDocx($contract, $guarantor);
                $filesToZip[] = $guarantorFilePath;
            }
        }
        $contractFilename = $contract->num . '_' . $typeSuffix . '_պայմանագիր.docx';
        $contractPath = storage_path('app/tmp/' . $contractFilename);

        if (!file_exists(dirname($contractPath))) {
            mkdir(dirname($contractPath), 0775, true);
        }

        $templateProcessor->saveAs($contractPath);
        $filesToZip[] = $contractPath;

        [$individualTempPath] = $this->generateIndividualSheetDocx($contract);
        $filesToZip[] = $individualTempPath;

        $zipFileName = $contract->num . '_փաստաթղթեր.zip';
        $zipFilePath = storage_path('app/tmp/' . $zipFileName);

        $this->activity->log('download_contract_file', 'Contract #' . $contract->num, Contract::class, $contract->id);

        $zip = new \ZipArchive;
        if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            foreach ($filesToZip as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        }

        foreach ($filesToZip as $file) {
            if (file_exists($file)) unlink($file);
        }

        return response()->download($zipFilePath, $zipFileName)->deleteFileAfterSend(true);
    }

    private function formatArmenianDate(string $date): string
    {
        $months = [
            1 => 'Հունվար', 2 => 'Փետրվար', 3 => 'Մարտ',    4 => 'Ապրիլ',
            5 => 'Մայիս',   6 => 'Հունիս',   7 => 'Հուլիս',  8 => 'Օգոստոս',
            9 => 'Սեպտեմբեր', 10 => 'Հոկտեմբեր', 11 => 'Նոյեմբեր', 12 => 'Դեկտեմբեր',
        ];
        $parsed = Carbon::parse($date);
        return $parsed->format('d') . ' ' . $months[$parsed->month] . ', ' . $parsed->format('Y');
    }

    private function generateGuarantorDocx($contract, $guarantor): array
    {
        $templatePath = public_path('files/guarantor_contract.docx');

        if (!file_exists($templatePath)) {
            abort(404, "Guarantor template not found");
        }

        $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

        $guarantorName = $guarantor->name . ' ' . $guarantor->surname;

        $templateProcessor->setValues([
            'date' => \Carbon\Carbon::parse($contract->date)->format('d.m.Y'),

            'num' => $contract->num,
            'contr_amount' => $this->makeMoney((int)$contract->contract_amount),

            'guarantor' => $guarantorName,
            'g_pass_ser' => $guarantor->passport_series,
            'g_pass_val' => \Carbon\Carbon::parse($guarantor->passport_validity)->format('d.m.Y'),
            'g_pass_iss' => $guarantor->passport_issued,
            'g_soc_num' => $guarantor->social_card_number ?? $guarantor->tax_number ?? '',

            'phone' => $guarantor->phone,
            'bank_name' => $guarantor->bank_name,
            'account_number' => $guarantor->account_number,
            'card_number' => $guarantor->card_number,

            'client' => $contract->client->name . ' ' . $contract->client->surname,
        ]);

        $fileName = $contract->num . '_երաշխավոր_' . $guarantor->id . '.docx';
        $filePath = storage_path('app/tmp/' . $fileName);

        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0775, true);
        }

        $templateProcessor->saveAs($filePath);

        return [$filePath];
    }
    private function generateIndividualSheetDocx(Contract $contract): array
    {
        $client = $contract->client;
        $providedAt = $contract->provided_at;

        $templatePath = public_path('files/contract_individual.docx');
        if (!file_exists($templatePath)) {
            abort(404, "Template not found: contract_individual.docx");
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        $clientName = $client->name . ' ' . $client->surname;
        $fullAddress = ($client->acctual_statement->address ?? $client->city) . ', '
            . ($client->actual_street_building ?? $client->street);
        $firstPayment = $contract->payments->where('status', 'initial')->first();

        $categoryTitle = $contract->category?->title
            ?? $contract->items->first()?->category?->title
            ?? 'Վարկ';

        $interestAmount = 0;
        $providedAmount = 0;

        if ($providedAt) {
            $interestAmount = $contract->payment_type == 'amortized'
                ? $contract->payments->where('status', 'initial')->sum('interest_payment')
                : $contract->payments->where('status', 'initial')->sum('amount');

            $providedAmount = $this->makeMoney((int)$contract->provided_amount);
        }

        $templateProcessor->setValues([
            'name'               => $categoryTitle,
            'amount'             => $this->makeMoney((int)$contract->contract_amount),
            'deadline'           => $contract->deadline_days ?? 0,
            'int_rate'           => round($contract->interest_rate * 365, 2),
            'eff_rate'           => round($contract->effective_annual_rate, 2),

            'first_paid_date'    => $providedAt && $firstPayment ? \Carbon\Carbon::parse($firstPayment->date)->format('d.m.Y') : '',
            'first_paid_amount'  => $providedAt && $firstPayment ? $this->makeMoney((int)$firstPayment->amount) : '0',

            'client'             => $clientName,
            'address'            => $fullAddress,
            'phone'              => $client->phone ?? '',
            'mail'               => $client->email ?? '',
            'date'               => \Carbon\Carbon::parse($contract->date)->format('d.m.Y'),
            'category'           => $contract->items->first()?->category?->title ?? 'Վարկ',

            'provided_amount' => $providedAmount,
            'interest_amount'    => $this->makeMoney((int)$interestAmount),
        ]);

        $fileName = 'Անհատական_թերթիկ_' . $contract->num . '.docx';
        $tempPath = storage_path('app/tmp/' . $fileName);

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0775, true);
        }

        $templateProcessor->saveAs($tempPath);

        return [$tempPath, $fileName];
    }

    public function downloadIndividualSheet($id)
    {
        $contract = Contract::with(['client', 'category', 'items.category', 'payments'])->findOrFail($id);

        [$tempPath, $fileName] = $this->generateIndividualSheetDocx($contract);

        $this->activity->log(
            'download_individual_sheet',
            'Individual Sheet #' . $contract->num,
            Contract::class,
            $contract->id
        );

        return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);
    }
    public function downloadBond($id)
    {
        $templateProcessor = new TemplateProcessor(public_path('/files/gravatoms_template.docx'));
        $contract = Contract::where('id', $id)->with(['payments', 'pawnshop', 'items' => function ($query) {
            $query->with('category');
        }])->first();
        $client_name = $contract->client->name . ' ' . $contract->client->surname . ' ' . $contract->client->middle_name;
        $templateProcessor->setValues([
            'client_name' => $client_name,
            'client_dob' => $contract->dob,
            'client_passport' => $contract->passport,
            'client_given' => $contract->passport_given,
            'client_address' => $contract->address,
            'given' => $this->makeMoney((int)$contract->given),
            'given_text' => $this->numberToText($contract->given),
            'contract_id' => $contract->ADB_ID,
            'price' => $contract->given,
            'date' => Carbon::parse($contract->date)->format('d.m.Y'),
        ]);
        $table_values = [];
        foreach ($contract->items as $item) {
            $table_values[] = [
                'item_description' => $item->category->title . $item->description,
                'i_t' => $item->type,
                'i_w' => $item->weight,
                'i_cw' => $item->clear_weight
            ];
        }
        $templateProcessor->cloneRowAndSetValues('item_description', $table_values);
        $filename = time() . 'bond.docx';
        $pathToSave = public_path('/files/download/' . $filename);
        $templateProcessor->saveAs($pathToSave);
        $downloadName = $contract->ADB_ID . '_Գրավատոմս.docx';
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename='.$downloadName,
        ];

        // Return the document as a response and delete the temporary file after sending
        return response()->file($pathToSave, $headers)->deleteFileAfterSend(true);
    }

    public function downloadOrder($id)
    {
        if (!$id) {
            return response()->json([
                'message' => 'Provided id is null'
            ]);
        }
        $order = Order::where('id', $id)->first();

        $this->activity->log(
            'download_order',
            'User downloaded order #' . $order->id,
            Order::class,
            $order->id,
        );
        if ($order) {
            switch ($order->type) {
                case 'in':
                    return $this->downloadOrderIn($id);
                    break;
                case 'out':
                    return $this->downloadOrderOut($id);
                    break;
                case 'cost_in':
                    return $this->downloadCostOrderIn($id);
                    break;
                case 'cost_out':
                    return $this->downloadCostOrderOut($id);
                    break;
            }
        }else{
            abort(404,"Page Not Found");
        }
    }

    public function downloadOrderIn($id)
    {
        $order = Order::where('id', $id)->first();
        if ($order->cash) {
            $templateProcessor = new TemplateProcessor(public_path('/files/contract_order_in_cash_template.docx'));
        } else {
            $templateProcessor = new TemplateProcessor(public_path('/files/contract_order_in_template.docx'));
        }
        $contract = Contract::where('id', $order->contract_id)->first();

        if ($order->filter == Order::FULL_FILTER) {
            $lumpAmount = Order::where('contract_id', $order->contract_id)
                ->where('filter', Order::REFUND_LUMP_FILTER)
                ->select('amount')
                ->first();
            $lumpAmountValue = $lumpAmount?->amount ?? 0;
            $amount1 = $this->makeMoney($order->amount - $lumpAmountValue);
        } else {
            $amount1 = $this->makeMoney($order->amount);
        }

        $user = $order->user ?? \App\Models\User::first();
        $client = $contract?->client;

        $templateProcessor->setValues([
            'amount1' => $amount1,
            'amount2' => $this->makeMoney($order->amount),
            'rep_id' => 2211,
            'num' => $contract->num,
            'order' => $order->order,
            'date' => isset($order->date) ? $this->formatArmenianDate($order->date) : null,
            'receiver' => $order->receiver,
            'client' => $client ? $client->name . ' ' . $client->surname . ' ' . ($client->middle_name ?? '') : null,
            'contract_id' => $contract->num ?? null,
            'purpose' => $order->purpose,
            'amount1_text' => $this->numberToText((float) str_replace([' ', ',','.'], ['', '',''], $amount1)),
            'amount2_text' => $this->numberToText($order->amount),
            'phone' => $contract?->client->phone,
            'executor' => $user ? $user->name . ' ' . $user->surname : null,
        ]);
        $filename = time() . 'order_in.docx';
        $pathToSave = public_path('/files/download/' . $filename);
        $templateProcessor->saveAs($pathToSave);
        $downloadName = $order->order . 'Մուտքի Օրդեր.docx';

        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename=' . $downloadName,
        ];
        // Return the document as a response and delete the temporary file after sending
        return response()->file($pathToSave, $headers)->deleteFileAfterSend(true);
    }

    public function downloadOrderOut($id)
    {
        $templateProcessor = new TemplateProcessor(public_path('/files/contract_order_out_template.docx'));
        $order = Order::where('id', $id)->first();
        $contract = Contract::where('id', $order->contract_id)->with('client')->first();
        $client = $contract?->client;
        $user = $order->user ?? \App\Models\User::first();

        $passportDate = $client?->passport_validity
            ? Carbon::parse($client->passport_validity)->format('d.m.Y')
            : null;
        $basis = trim(implode('', [
            $client?->passport_series ?? '',
            $passportDate ? ', տրվ. ' . $passportDate . 'թ. ' : '',
            $client?->passport_issued ?? '',
        ]));

        $templateProcessor->setValues([
            'amount' => isset($order->amount) ? $this->makeMoney($order->amount) : null,
            'purpose' => $order->purpose ?? null,
            'rep_id' => $order->rep_id ?? null,
            'order' => $order->order ?? null,
            'date' => isset($order->date) ? $this->formatArmenianDate($order->date) : null,
            'receiver' => $order->receiver ?? null,
            'contract_id' => $contract?->num,
            'num' => $contract?->num ?? null,
            'client' => $client ? $client->name . ' ' . $client->surname . ' ' . ($client->middle_name ?? '') : null,
            'cl_dob' => $client?->date_of_birth
                ? Carbon::parse($client->date_of_birth)->format('d.m.Y')
                : null,
            'cl_pass' => $client?->passport_series ?? null,
            'cl_val' => $passportDate,
            'cl_iss' => $client?->passport_issued ?? null,
            'basis' => $basis ?: null,
            'account_number' => $client?->account_number ?? $client?->card_number ?? null,
            'amount_text' => isset($order->amount) ? $this->numberToText($order->amount) : null,
            'executor' => $user ? $user->name . ' ' . $user->surname : null,
        ]);

        $filename = time() . 'order_out.docx';
        $pathToSave = public_path('/files/download/' . $filename);
        $templateProcessor->saveAs($pathToSave);
        $downloadName = $order->order . ' Ելքի Օրդեր.docx';
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename=' . $downloadName,
        ];
        // Return the document as a response and delete the temporary file after sending
        return response()->file($pathToSave, $headers)->deleteFileAfterSend(true);
    }


    public function downloadCostOrderIn($id)
    {
        $templateProcessor = new TemplateProcessor(public_path('/files/cost_in_template.docx'));
        $order = Order::where('id', $id)->first();
        $templateProcessor->setValues([
            'amount' => $this->makeMoney($order->amount),
            'receiver' => $order->receiver,
            'order' => $order->order,
            'date' => Carbon::parse($order->date)->format('d.m.Y'),
            'purpose' => $order->purpose,
            'amount_text' => $this->numberToText($order->amount),
        ]);
        $filename = time() . 'cost_order_in.docx';
        $pathToSave = public_path('/files/download/' . $filename);
        $templateProcessor->saveAs($pathToSave);
        $downloadName = $order->order . ' Ծախս.docx';
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename=' . $downloadName,
        ];
        // Return the document as a response and delete the temporary file after sending
        return response()->file($pathToSave, $headers)->deleteFileAfterSend(true);
    }
    public function downloadAllFiles($id)
    {
        $contract = Contract::where('id', $id)->firstOrFail();

        $zipFileName = "contract_{$contract->num}_files.zip";
        $zipFilePath = public_path("/files/download/" . $zipFileName);

        $zip = new ZipArchive;

        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {

            $contractFile = $this->downloadContract($id);
            $contractFilePath = $contractFile->getFile()->getPathname();
            $zip->addFile($contractFilePath, "{$contract->num}_Գրավատոմս_և_Պայմանագիր.docx");

//            $bondFile = $this->downloadBond($id);
//            $bondFilePath = $bondFile->getFile()->getPathname();
//            $zip->addFile($bondFilePath, "{$id}_Գրավատոմս.docx");

            $orders = Order::where('contract_id', $id)->get();
            foreach ($orders as $order) {
                $orderFile = $this->downloadOrder($order->id);
                if ($orderFile) {
//                    $orderFilePath = $orderFile->getFile()->getPathname();
//                    $zip->addFile($orderFilePath, "{$order->order}_Order.docx");
//                    dd($orderFile->headers->get('content-disposition'));
                    $orderFilePath = $orderFile->getFile()->getPathname();
//
//                    $orderFileName = basename($orderFilePath);
                    $orderFileName = null;
                    if ($orderFile->headers->has('content-disposition')) {
                        $contentDisposition = $orderFile->headers->get('content-disposition');

                        if (preg_match('/filename="?(?<filename>[^"]+)"?/', $contentDisposition, $matches)) {
                            $orderFileName = $matches['filename'];
                        }
                    }

                    $zip->addFile($orderFilePath, $orderFileName);
                }
            }

            $zip->close();
        }

        return response()->download($zipFilePath)->deleteFileAfterSend(true);
    }
    public function exportZip()
    {
        $timestamp = now()->format('Y_m_d_H_i_s');
        $paymentsFile = "exports/payments_{$timestamp}.xlsx";
        $contractsFile = "exports/contracts_{$timestamp}.xlsx";
        $dealsFile = "exports/deal_{$timestamp}.xlsx";
        $zipFileName = "exports/exports_{$timestamp}.zip";

        Excel::store(new PaymentsExport, $paymentsFile);
        Excel::store(new ContractsExport, $contractsFile);
        Excel::store(new DealsExport, $dealsFile);

        $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
        $zipPath = storage_path("app/{$zipFileName}");

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFile(storage_path("app/{$paymentsFile}"), 'Payments.xlsx');
            $zip->addFile(storage_path("app/{$contractsFile}"), 'Contracts.xlsx');
            $zip->addFile(storage_path("app/{$dealsFile}"), 'Deals.xlsx');
            $zip->close();
        }

        Storage::delete([$paymentsFile, $contractsFile]);

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

}
