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

    public function downloadContract1($id)
    {
        $contract = Contract::with(['client', 'items.category', 'pawnshop', 'payments'])->findOrFail($id);

        $hasCar = $contract->items->contains(fn($item) => $item->category->name === 'car');

        $client = $contract->client;
        $pawnshop = $contract->pawnshop;

        $filesToZip = [];

        $templateFile = $hasCar ? 'contract_bond_car_template.docx' : 'contract_bond_template.docx';
        $templateProcessor = new TemplateProcessor(public_path('files/' . $templateFile));

        $client_name = $client->name . ' ' . $client->surname . ' ' . ($client->middle_name ?? '');
        $name_surname =  $client_name = $client->name . ' ' . $client->surname;

        $client_numbers = $client->phone;
        if ($client->additional_phone) {
            $client_numbers .= ', ' . $client->additional_phone;
        }

        $pawnshop_numbers = $pawnshop->telephone;
        if ($pawnshop->phone1) $pawnshop_numbers .= ', ' . $pawnshop->phone1;
        if ($pawnshop->phone2) $pawnshop_numbers .= ', ' . $pawnshop->phone2;

        $yearly_rate = $contract?->category?->name === 'electronics' ? 158.39 : $contract->interest_rate * 365;
        $cash = $contract->provided_amount > 20000 ? 'անկանխիկ' : 'կանխիկ';
        $o_t_p = $contract->provided_amount >= 400000 ? '2' : '2,5';
        $rate_percentage = $contract->estimated_amount > 0
            ? round(($contract->provided_amount / $contract->estimated_amount) * 100, 2)
            : 0;

        $templateProcessor->setValues([
            'city' => $pawnshop->city,
            'date' => Carbon::parse($contract->date)->format('d.m.Y'),
            'license' => $pawnshop->license,
            'address' => $pawnshop->address,
            'representative' => $pawnshop->representative,
            'client_name' => $client_name,
            'client_dob' => Carbon::parse($client->date_of_birth)->format('d.m.Y'),
            'client_passport' => $client->passport_series,
            'client_given' => $client->passport_issued,
            'client_address' => ($client->country === 'Armenia' ? 'Հայաստան' : $client->country) . ', ' . $client->city . ', ' . $client->street,
            'client_numbers' => $client_numbers,
            'given' => $this->makeMoney((int)$contract->provided_amount),
            'rate_percentage' => $rate_percentage,
            'given_text' => $this->numberToText($contract->mother),
            'contract_id' => $contract->num,
            'deadline' => Carbon::parse($contract->deadline)->format('d.m.Y'),
            'dl_ds' => Carbon::parse($contract->deadline)->diffInDays(Carbon::parse($contract->date)),
            'dl_dt' => Carbon::parse($contract->deadline)->format('d'),
            'psh_numbers' => $pawnshop_numbers,
            'psh_mail' => $pawnshop->email,
            'psh_bank' => $pawnshop->bank,
            'psh_card' => preg_replace('/(\d{4})(?=\d)/', '$1 ', $pawnshop->card_account_number),
            'client_bank' => $client->bank_name,
            'client_card' => preg_replace('/(\d{4})(?=\d)/', '$1 ', $client->card_number),
            'rate' => $contract->interest_rate,
            'yr_rate' => $yearly_rate,
            'penalty' => $contract->penalty,
            'o_t_p' => $o_t_p,
            'cash' => $cash,
        ]);

        $table_values = [];
        $car_values = [];
        $car = null;

        foreach ($contract->items as $item) {
            if ($item->category->name === 'car') {
                $car = $item;
                $itemName =  $item->category->title . ($item->model ? ', ' . $item->model : '') . ($contract->description ? '. ' . $contract->description : '');
                $car_values = [
                    'item' => $itemName,
                    'desc' => $contract->description,
                    'i_c' => $item->model,
                    'i_m' => $item->car_make,
                    'i_man' => $item->manufacture,
                    'i_col' => $item->color,
                    'i_l' => $item->license_plate,
                    'i_i' => $item->identification,
                    'i_p' => $item->power,
                    'i_r' => $item->registration,
                    'i_o' => $item->ownership,
                    'i_iss' => $item->issued_by,
                    'i_d' => Carbon::parse($item->date_of_issuance)->format('d.m.Y'),
                    'price' => $item->estimated_amount ? $this->makeMoney((int) $item->estimated_amount) :
                        $this->makeMoney((int) $contract->estimated_amount),

                ];
            } else {
                $itemName =  $item->category->title . ($item->subcategory ? ', ' . $item->subcategory : '')
                    . ($item->model ? ', ' . $item->model : '') . ($item->sn ? ', ' . $item->sn : '')
                    . ($item->imei ? ', ' . $item->imei : '') . ($contract->description ? '. ' . $contract->description : '');
                $table_values[] = [
                    'item' => $itemName,
                    'desc' => $contract->description,
                    'i_t' => $item->hallmark,
                    'i_w' => $item->weight,
                    'i_cw' => $item->clear_weight,
                    'price' => $item->estimated_amount ? $this->makeMoney((int) $item->estimated_amount) :
                        $this->makeMoney((int) $contract->estimated_amount),
                ];
            }
        }

        if ($hasCar) {
            $templateProcessor->setValues($car_values);
        } else {
            $templateProcessor->cloneRowAndSetValues('item', $table_values);
        }

        $payment_values = [];
        $i = 1;
        $payments = $contract->payment_schedule ?? $contract->payments;
        foreach ($payments as $payment) {
            $payment_values[] = [
                'p_n' => $i . '.',
                'p_d' => Carbon::parse($payment['date'] ?? $payment->date)->format('d.m.Y'),
                'p_m' => $payment['amount'] ?? $payment->amount,
                'p_text' => $this->numberToText($payment['amount'] ?? $payment->amount)
            ];
            $i++;
        }
        $templateProcessor->cloneRowAndSetValues('p_n', $payment_values);

        $contractFilename = $contract->num . '_Պայմանագիր.docx';
        $contractPath = storage_path('app/tmp/' . $contractFilename);
        if (!file_exists(dirname($contractPath))) {
            mkdir(dirname($contractPath), 0775, true);
        }
        $templateProcessor->saveAs($contractPath);
        $filesToZip[] = $contractPath;

        if ($hasCar && $car) {
            $actTemplate = new TemplateProcessor(public_path('files/car_act.docx'));
            $actTemplate->setValues([
                'date' => Carbon::parse($contract->date)->format('d.m.Y') . 'թ.',
                'full_name' => $client_name,
                'passport' => $client->passport_series,
                'validity' => Carbon::parse($client->passport_validity)->format('d.m.Y'). 'թ.',
                'issued' =>  'տրվ.' . $client->passport_issued,
                'city' => $client->city,
                'street' => $client->street,
                'contract_num' => $contract->num,
                'car_model' => $car->model,
                'license_plate' => $car->license_plate,
                'name_surname' => $name_surname
            ]);

            $actFilename = $contract->num . '_Ակտ.docx';
            $actPath = storage_path('app/tmp/' . $actFilename);
            $actTemplate->saveAs($actPath);
            $filesToZip[] = $actPath;
            $applicationTemplate = new TemplateProcessor(public_path('files/car_application.docx'));
            $registration = str_replace(' ', '', $car->registration);

            $registrationSeria = mb_substr($registration, 0, 2);
            $registrationNum = mb_substr($registration, 2);

            $applicationTemplate->setValues([
                'passport' => $client->passport_series,
                'validity' => Carbon::parse($client->passport_validity)->format('d.m.Y'). 'թ.',
                'issued' => $client->passport_issued . '-ի կողմից',
                'client' => $client_name,
                'city' => $client->city,
                'street' => $client->street,
                'date' => Carbon::parse($contract->date)->format('d.m.Y') . 'թ․',
                'car_model' => $car->model . ' ' . $car->car_make,
                'identification' => $car->identification,
                'license_plate' => $car->license_plate,
                'manufacture' => $car->manufacture,
                'color' => $car->color,
                'power' => $car->power,
                'registration_seria' => $registrationSeria,
                'registration_num' => $registrationNum,
                'provided_amount' => $this->makeMoney((int) $contract->provided_amount),
                'end_date' => Carbon::parse($contract->deadline)->format('d.m.Y'). 'թ.',
            ]);

            $applicationFilename = $contract->num . '_Դիմում.docx';
            $applicationPath = storage_path('app/tmp/' . $applicationFilename);
            $applicationTemplate->saveAs($applicationPath);
            $filesToZip[] = $applicationPath;
        }

        $zipFileName = $contract->num . '_փաստաթղթեր.zip';
        $zipFilePath = storage_path('app/tmp/' . $zipFileName);

        $zip = new ZipArchive;
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
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
        $contract = Contract::with(['client', 'items.category', 'pawnshop', 'payments', 'user'])->findOrFail($id);

        $client = $contract->client;
        $user = $contract->user;
        $filesToZip = [];

        $firstItem = $contract->items->first();
        $categoryName = $firstItem->category->name ?? 'gold';

        $templateFileName = ($categoryName == 'car') ? 'contract_car_template.docx' : 'contract_gold_template.docx';
        $templatePath = public_path('files/' . $templateFileName);

        if (!file_exists($templatePath)) {
            abort(404, "Template not found: " . $templateFileName);
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        $clientName = $client->name . ' ' . $client->surname;
        $userName = $user ? ($user->name . ' ' . $user->surname) : '---';
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
        if (!empty($paymentRows)) {
            $templateProcessor->cloneRowAndSetValues('p_d', $paymentRows);
        }

        $itemRows = [];
        $totals = ['count' => 0, 'weight' => 0, 'clear_weight' => 0, 'amount' => 0, 'sum' => 0];

        foreach ($contract->items as $item) {
            $amount = $item->provided_amount ?? $contract->mother;

            if ($categoryName == 'gold') {
                $count = $item->count ?? 1;
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

                $totals['count']  += $count;
                $totals['weight'] += (float)$item->weight;
                $totals['clear_weight'] += (float)$item->clear_weight;
                $totals['amount'] += (float)$amount;
                $totals['sum']    += $rowTotal;
            } else {
                $itemRows[] = [
                    'i_car_make' => $item->car_make,
                    'i_mod'      => $item->model,
                    'i_pow'      => $item->power,
                    'i_man'      => $item->manufacture,
                    'i_colo'     => $item->color,
                    'i_reg'      => $item->registration,
                    'i_own'      => $item->ownership,
                    'i_ident'    => $item->identification,
                    'i_est'      => $contract->estimated_amount,
                    'i_prov'     => $amount
                ];
            }
        }

        if (!empty($itemRows)) {
            $rowId = ($categoryName == 'car') ? 'i_car_make' : 'i_desc';
            $templateProcessor->cloneRowAndSetValues($rowId, $itemRows);
        }

        if ($categoryName == 'gold') {
            $templateProcessor->setValues([
                't_i_c' => $totals['count'],
                't_i_w' => $totals['weight'],
                't_i_cw' => $totals['clear_weight'],
                't_i_am' => $totals['amount'],
                't_i_total_am' => $totals['sum'],
            ]);
        }

        $typeSuffix = ($categoryName == 'car') ? 'ավտոյի' : 'ոսկու';
        $contractFilename = $contract->num . '_' . $typeSuffix . '_պայմանագիր.docx';
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
            if (file_exists($file)) { unlink($file); }
        }

        return response()->download($zipFilePath, $zipFileName)->deleteFileAfterSend(true);
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
            'given' => $this->makeMoney($contract->given),
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
        $templateProcessor = new TemplateProcessor(public_path('/files/contract_order_in_template.docx'));
        $order = Order::where('id', $id)->first();
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

        $templateProcessor->setValues([
            'amount1' => $amount1,
            'amount2' => $this->makeMoney($order->amount),
            'rep_id' => 2211,
            'order' => $order->order,
            'date' => Carbon::parse($order->date)->format('d.m.Y'),
            'receiver' => $order->receiver,
            'contract_id' => $contract->num ?? null,
            'purpose' => $order->purpose,
            'amount1_text' => $this->numberToText((float) str_replace([' ', ',','.'], ['', '',''], $amount1)),
            'amount2_text' => $this->numberToText($order->amount),
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
        $templateProcessor->setValues([
            'amount' => isset($order) && isset($order->amount) ? $this->makeMoney($order->amount) : null,
            'purpose' => $order->purpose ?? null,
            'rep_id' => $order->rep_id ?? null,
            'order' => $order->order ?? null,
            'date' => isset($order->date) ? Carbon::parse($order->date)->format('d.m.Y') : null,
            'receiver' => $order->receiver ?? null,
            'contract_id' => $contract?->num,
            'cl_dob' => $contract?->client?->date_of_birth
                ? Carbon::parse($contract->client->date_of_birth)->format('d.m.Y')
                : null,
            'cl_pas' => $contract?->client?->passport_series ?? null,
            'cl_giv' => $contract?->client?->passport_issued ?? null,
            'amount_text' => isset($order->amount) ? $this->numberToText($order->amount) : null,
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
