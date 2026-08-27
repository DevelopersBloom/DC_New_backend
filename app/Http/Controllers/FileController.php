<?php

namespace App\Http\Controllers;

use App\Exports\ContractsExport;
use App\Exports\DealsExport;
use App\Exports\PaymentsExport;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Order;
use App\Services\ActivityService;
use App\Services\ContractCalculationService;
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
    protected ContractCalculationService $contractCalculationService;

    public function __construct(ActivityService $activity, ContractCalculationService $contractCalculationService)
    {
        $this->activity = $activity;
        $this->contractCalculationService = $contractCalculationService;
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
        $contract = Contract::with(['category', 'client', 'items.category', 'pawnshop', 'payments', 'user','seller','guarantors','pledgee'])->findOrFail($id);

        $client = $contract->client;
        $firstItem = $contract->items->first();
        $categoryName = $firstItem->category->name ?? 'gold';
        $filesToZip = [];

        [$contractPath] = $this->generateMainContractDocx($contract);
        $filesToZip[] = $contractPath;

        if ($categoryName == 'car') {
            $carApplication = $this->generateCarApplicationDocx($contract);
            if ($carApplication) {
                [$appPathToSave] = $carApplication;
                $filesToZip[] = $appPathToSave;
            }
        }

        if ($contract->guarantors && $contract->guarantors->count()) {
            foreach ($contract->guarantors as $guarantor) {
                [$guarantorFilePath] = $this->generateGuarantorDocx($contract, $guarantor);
                $filesToZip[] = $guarantorFilePath;
            }
        }

        [$individualTempPath] = $this->generateIndividualSheetDocx($contract);
        $filesToZip[] = $individualTempPath;

        if ($client->type === 'individual' && $categoryName == 'car' && !is_null($client->is_married)) {
            $maritalDocPath = $this->attachMaritalStatusDocx($client, $contract, $firstItem);
            if ($maritalDocPath) {
                $filesToZip[] = $maritalDocPath;
            }
        }

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

    public function downloadContractDoc($id)
    {
        $contract = Contract::with(['category', 'client', 'items.category', 'payments', 'user', 'seller', 'pledgee'])->findOrFail($id);

        [$path, $filename] = $this->generateMainContractDocx($contract);

        $this->activity->log('download_contract_file', 'Contract #' . $contract->num . ' (main doc)', Contract::class, $contract->id);

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    public function downloadCarApplicationDoc($id)
    {
        $contract = Contract::with(['client', 'items.category', 'guarantors'])->findOrFail($id);

        $result = $this->generateCarApplicationDocx($contract);
        if (!$result) {
            abort(404, 'Contract has no car item to generate an application for');
        }
        [$path, $filename] = $result;

        $this->activity->log('download_contract_file', 'Contract #' . $contract->num . ' (car application)', Contract::class, $contract->id);

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    public function downloadGuarantorDoc($id, $guarantorId)
    {
        $contract = Contract::with(['client', 'guarantors'])->findOrFail($id);

        $guarantor = $contract->guarantors->firstWhere('id', (int) $guarantorId);
        if (!$guarantor) {
            abort(404, 'Guarantor not found for this contract');
        }

        [$path] = $this->generateGuarantorDocx($contract, $guarantor);

        $this->activity->log('download_contract_file', 'Contract #' . $contract->num . ' (guarantor doc)', Contract::class, $contract->id);

        return response()->download($path, basename($path))->deleteFileAfterSend(true);
    }

    public function downloadMaritalStatusDoc($id)
    {
        $contract = Contract::with(['client', 'items.category'])->findOrFail($id);
        $client = $contract->client;
        $firstItem = $contract->items->first();

        $path = $this->attachMaritalStatusDocx($client, $contract, $firstItem);
        if (!$path) {
            abort(404, 'Marital status document not available for this contract');
        }

        $this->activity->log('download_contract_file', 'Contract #' . $contract->num . ' (marital status doc)', Contract::class, $contract->id);

        return response()->download($path, basename($path))->deleteFileAfterSend(true);
    }

    private function generateMainContractDocx(Contract $contract): array
    {
        $client = $contract->client;
        $seller = $contract->seller;
        $user = $contract->user;

        $firstItem = $contract->items->first();
        $categoryName = $firstItem->category->name ?? 'gold';

        if ($categoryName == 'car') {
            $templateFileName = $contract->pledgee ? 'contract_car_template_pledgee.docx' : 'contract_car_template.docx';
        } elseif ($categoryName == 'gold') {
            $templateFileName = 'contract_gold_template.docx';
        } elseif ($categoryName == 'car-purchase') {
            $templateFileName = 'contract_buying_car_template.docx';
        }
        if (!$templateFileName) {
            abort(404, 'Template file not found');
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
        $deadline = \Carbon\Carbon::parse($contract->deadline)->format('d.m.Y');
        $city = $client->actual_province ?? $client->city;
        $street = $client->actual_street_building ?? $client->street;
        $cardNumber = $client->account_number ? '' : $client->card_number;
        $templateProcessor->setValues([
            'num' => $contract->num,
            'date' => \Carbon\Carbon::parse($contract->date)->format('d.m.Y'),
            'client' => $clientName,
            'passport_series' => $client->passport_series,
            'passport_validity' => \Carbon\Carbon::parse($client->passport_validity)->format('d.m.Y'),
            'passport_issued' => $client->passport_issued,
            'social_card_number' => $client->social_card_number ?? $client->tax_number ?? '',
            'city' => $city,
            'street' => $street,
            'phone' => $client->phone ?? $contract->additional_phone ?? '',
            'contract_amount' => $this->makeMoney((int)$contract->contract_amount),
            'mother_amount' => $this->makeMoney((int)$contract->estimated_amount),
            'interest_annual_rate' => $yearlyRate . ' %',
            'effective_annual_rate' => $effectiveRate . ' %',
            'deadline' => $deadline,
            'bank_name' => $client->bank_name,
            'provided_type' => $client->account_number ?? $client->card_number,
            'account_card_number' => $client->account_number,
            'card_number' => $cardNumber,
            'user_name' => $userName,
            'lump_rate' => $lumpRate,
            'seller' => $sellerName,
            'seller_email' => $seller?->email,
            'seller_bank_name' => $seller?->bank_name,
            'seller_account_number' => $seller?->account_number,
            'seller_address' => $seller?->city . ',' . $seller?->street,
            'seller_code' => $sellerCode,
        ]);

        if ($categoryName == 'car' && $contract->pledgee) {
            $pledgee = $contract->pledgee;
            $pledgeeName = $pledgee->name . ' ' . $pledgee->surname . ($pledgee->middle_name ? ' ' . $pledgee->middle_name : '');
            $pledgeeCity = $pledgee->actual_province ?? $pledgee->city;
            $pledgeeStreet = $pledgee->actual_street_building ?? $pledgee->street;

            $templateProcessor->setValues([
                'pledgee' => $pledgeeName,
                'pl_passport_series' => $pledgee->passport_series,
                'pl_passport_validity' => \Carbon\Carbon::parse($pledgee->passport_validity)->format('d.m.Y'),
                'pl_passport_issued' => $pledgee->passport_issued,
                'pl_social_card_number' => $pledgee->social_card_number ?? $pledgee->tax_number ?? '',
                'pl_city' => $pledgeeCity,
                'pl_street' => $pledgeeStreet,
                'pl_phone' => $pledgee->phone ?? '',
            ]);
        }

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
        if (!empty($paymentRows) && in_array('p_d', $templateProcessor->getVariables(), true)) {
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

        $contractFilename = $contract->num . '_' . $typeSuffix . '_պայմանագիր.docx';
        $contractPath = storage_path('app/tmp/' . $contractFilename);

        if (!file_exists(dirname($contractPath))) {
            mkdir(dirname($contractPath), 0775, true);
        }

        $templateProcessor->saveAs($contractPath);

        return [$contractPath, $contractFilename];
    }

    private function generateCarApplicationDocx(Contract $contract): ?array
    {
        $firstItem = $contract->items->first();
        $categoryName = $firstItem->category->name ?? null;

        if ($categoryName !== 'car' || !$firstItem) {
            return null;
        }

        $templatePath = public_path('files/application_car.docx');
        if (!file_exists($templatePath)) {
            abort(404, "Template not found: application_car.docx");
        }

        $appProcessor = new TemplateProcessor($templatePath);

        $client = $contract->client;
        $clientName = $client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : '');
        $pass = $client->passport_series . ', ' . \Carbon\Carbon::parse($client->passport_validity)->format('d/m.Y') . ', ' . $client->passport_issued;

        $guarantor = $contract->guarantors->first();
        $representativeName = $guarantor
            ? ($guarantor->name . ' ' . $guarantor->surname . ($guarantor->middle_name ? ' ' . $guarantor->middle_name : ''))
            : '';
        $representativePass = $guarantor && $guarantor->passport_series
            ? $guarantor->passport_series . ', ' . \Carbon\Carbon::parse($guarantor->passport_validity)->format('d/m/Y') . ', ' . $guarantor->passport_issued
            : '';

        $deadline = \Carbon\Carbon::parse($contract->deadline)->format('d.m.Y');
        $city = $client->actual_province ?? $client->city;
        $street = $client->actual_street_building ?? $client->street;
        $contractDate = \Carbon\Carbon::parse($contract->date);
        $appDay = $contractDate->format('d');
        $appMonthArm = $this->armenianMonths()[$contractDate->month];
        $appYear = $contractDate->format('Y');

        $appProcessor->setValues([
            'client' => $clientName,
            'pass' => $pass,
            'city' => $city,
            'street' => $street,
            'representative' => $representativeName,
            'rep_pass' => $representativePass,
            'model' => $firstItem->model . ' ' . $firstItem->car_make,
            'ident' => $firstItem->identification,
            'lic_pl' => $firstItem->license_plate,
            'man' => $firstItem->manufacture,
            'color' => $firstItem->color,
            'power' => $firstItem->power,
            'reg' => $firstItem->registration,
            'deadline' => $deadline,
            'contract_amount' => (int)$contract->contract_amount,
            'date' => \Carbon\Carbon::parse($contract->date)->format('d.m.Y'),
            'day' => $appDay,
            'month_arm' => $appMonthArm,
            'year' => $appYear,
        ]);

        $appFilename = $contract->num . '_մեքենայի_դիմում.docx';
        $appPathToSave = storage_path('app/tmp/' . $appFilename);

        if (!file_exists(dirname($appPathToSave))) {
            mkdir(dirname($appPathToSave), 0775, true);
        }

        $appProcessor->saveAs($appPathToSave);

        return [$appPathToSave, $appFilename];
    }

    private function armenianMonths(): array
    {
        return [
            1 => 'Հունվար', 2 => 'Փետրվար', 3 => 'Մարտ',    4 => 'Ապրիլ',
            5 => 'Մայիս',   6 => 'Հունիս',   7 => 'Հուլիս',  8 => 'Օգոստոս',
            9 => 'Սեպտեմբեր', 10 => 'Հոկտեմբեր', 11 => 'Նոյեմբեր', 12 => 'Դեկտեմբեր',
        ];
    }

    private function formatArmenianDate(string $date): string
    {
        $parsed = Carbon::parse($date);
        return $parsed->format('d') . ' ' . $this->armenianMonths()[$parsed->month] . ', ' . $parsed->format('Y');
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
    private function attachMaritalStatusDocx($client, Contract $contract,$firstItem): ?string
    {
        if ($client->is_married) {
            $templateFileName = 'agreement.docx';
            $label = 'Համաձայնություն';

        } else {
            $templateFileName = 'announcement.docx';
            $label = 'Հայտարարություն';
        }

        $templatePath = public_path('files/' . $templateFileName);

        if (!file_exists($templatePath)) {
            return null;
        }
        $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
        $clientInfo = $client->name . ' ' . $client->middle_name . ' ' . $client->surname;
        $date = Carbon::parse($contract->date)->format('d.m.Y');
        if ($client->is_married) {
            $templateProcessor->setValues([
                'date' => $date,
                'client' => $clientInfo,
                'model' => $firstItem->model . ' ' . $firstItem->car_make,
                'lic_pl' => $firstItem->license_plate,
            ]);
        } else {
            $templateProcessor->setValues([
                'date' => $date,
                'client' => $clientInfo,
            ]);
        }

        $fileName = $contract->num . '_' . $label . '.docx';
        $filePath = storage_path('app/tmp/' . $fileName);

        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0775, true);
        }

        $templateProcessor->saveAs($filePath);

        return $filePath;
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

    public function downloadCreditStatement(Request $request, $id)
    {
        $contract = Contract::with(['client', 'payments'])->findOrFail($id);

        $to = $request->query('to') ? Carbon::parse($request->query('to')) : now();
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : $to->copy()->startOfMonth();

        [$path, $filename] = $this->generateCreditStatementDocx($contract, $from, $to);

        $this->activity->log(
            'download_credit_statement',
            'Contract #' . $contract->num . ' վարկային քաղվածք',
            Contract::class,
            $contract->id
        );

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    private function generateCreditStatementDocx(Contract $contract, Carbon $from, Carbon $to): array
    {
        $templatePath = public_path('files/credit_statement_template.docx');
        if (!file_exists($templatePath)) {
            abort(404, 'Template not found: credit_statement_template.docx');
        }

        $client = $contract->client;
        $clientName = $client->type === 'legal'
            ? $client->company_name
            : ($client->name . ' ' . $client->surname);

        $reportStart = $from->copy()->startOfDay();
        $reportEnd = $to->copy()->endOfDay();

        $this->contractCalculationService->calculateAllMetrics($contract, $reportEnd->copy());

        // countPenalty() persists penalty_amount on the contract row as a side effect;
        // restore the pre-statement value so printing a past-period statement can't
        // overwrite the live cached penalty with a backdated figure.
        $penaltyAmountBeforeStatement = $contract->penalty_amount;
        $penaltyData = $this->contractCalculationService->countPenalty($contract->id, $reportEnd->toDateString());
        Contract::whereKey($contract->id)->update(['penalty_amount' => $penaltyAmountBeforeStatement]);

        $nextPayment = $contract->payments->where('status', 'initial')->sortBy('date')->first();

        $balanceAtEnd = $contract->payments
            ->filter(fn($p) => Carbon::parse($p->date)->lte($reportEnd))
            ->sortByDesc('date')
            ->first()
            ->remaining ?? $contract->provided_amount;

        $yearlyRate = round($contract->interest_rate * 365, 2);

        $templateProcessor = new TemplateProcessor($templatePath);

        $templateProcessor->setValues([
            'statement_num' => $contract->num . '-' . $reportEnd->format('Ymd'),
            'client'        => $clientName,
            'num'           => $contract->num,
            'date'          => Carbon::parse($contract->date)->format('d.m.Y'),
            'report_start'  => $reportStart->format('d.m.Y'),
            'report_end'    => $reportEnd->format('d.m.Y'),
            'report_days'   => $reportStart->diffInDays($reportEnd) + 1,

            'contract_amount' => $this->makeMoney((int)$contract->contract_amount),
            'provided_amont'  => $this->makeMoney((int)$balanceAtEnd),
            'deadline'        => Carbon::parse($contract->deadline)->format('d.m.Y'),
            'int_rate'        => $yearlyRate,

            'next_payment_date'      => $nextPayment ? Carbon::parse($nextPayment->to_date)->format('d.m.Y') : '',
            'next_payment_amount'    => $nextPayment ? $this->makeMoney((int)$nextPayment->amount) : '0',
            'next_payment_principal' => $nextPayment ? $this->makeMoney((int)$nextPayment->principal_payment) : '0',
            'next_payment_interest'  => $nextPayment ? $this->makeMoney((int)$nextPayment->interest_payment) : '0',
            'next_payment_fee'       => $nextPayment ? $this->makeMoney((int)$nextPayment->service_fee_payment) : '0',

            'overdue_total'     => $this->makeMoney((int)($contract->overdue_amount + $contract->overdue_interest)),
            'overdue_principal' => $this->makeMoney((int)$contract->overdue_amount),
            'overdue_interest'  => $this->makeMoney((int)$contract->overdue_interest),
            'overdue_fee'       => '0',

            'overdue_principal_penalty_rate'   => $contract->penalty,
            'overdue_principal_penalty_amount' => $this->makeMoney((int)$penaltyData['penalty_amount']),
            'overdue_interest_penalty_rate'    => $contract->penalty,
            'overdue_interest_penalty_amount'  => $this->makeMoney((int)$contract->overdue_amount_interest),
        ]);

        $periodDocs = DocumentJournal::query()
            ->where('contract_id', $contract->id)
            ->betweenDates($reportStart->toDateString(), $reportEnd->toDateString())
            ->orderBy('date')
            ->get();

        $sumPrincipal = (float)$periodDocs->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)->sum('amount_amd');
        $sumInterest = (float)$periodDocs->where('document_type', DocumentJournal::PAY_INTEREST_AMOUNT)->sum('amount_amd');
        $sumPenalty = (float)$periodDocs->where('document_type', DocumentJournal::PAY_PENALTY_AMOUNT)->sum('amount_amd');
        $sumTotal = $sumPrincipal + $sumInterest + $sumPenalty;

        $templateProcessor->setValues([
            'sum_principal'  => $this->makeMoney((int)$sumPrincipal),
            'sum_interest'   => $this->makeMoney((int)$sumInterest),
            'sum_commission' => '0',
            'sum_penalty'    => $this->makeMoney((int)$sumPenalty),
            'sum_total'      => $this->makeMoney((int)$sumTotal),
        ]);

        $transactionTypes = [
            DocumentJournal::PAY_MOTHER_AMOUNT,
            DocumentJournal::PAY_INTEREST_AMOUNT,
            DocumentJournal::PAY_PENALTY_AMOUNT,
        ];
        $transactionRows = [];
        foreach ($periodDocs->whereIn('document_type', $transactionTypes) as $doc) {
            $transactionRows[] = [
                't_date'   => $doc->date->format('d.m.Y'),
                't_desc'   => $doc->document_type,
                't_amount' => $this->makeMoney((int)$doc->amount_amd),
            ];
        }
        if (!empty($transactionRows)) {
            $templateProcessor->cloneRowAndSetValues('t_date', $transactionRows);
        } else {
            $templateProcessor->setValues([
                't_date'   => '',
                't_desc'   => '',
                't_amount' => '',
            ]);
        }

        $beforePeriodAmount = DocumentJournal::query()
            ->where('contract_id', $contract->id)
            ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
            ->where('date', '<', $reportStart->toDateString())
            ->sum('amount_amd');

        $templateProcessor->setValues([
            'before_period_date'   => $reportStart->format('d.m.Y'),
            'before_period_amount' => $this->makeMoney((int)$beforePeriodAmount),
        ]);

        $fileName = $contract->num . '_վարկային_քաղվածք_' . $reportEnd->format('Ymd') . '.docx';
        $filePath = storage_path('app/tmp/' . $fileName);

        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0775, true);
        }

        $templateProcessor->saveAs($filePath);

        return [$filePath, $fileName];
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
        if ($order) {
            $this->activity->log(
                'download_order',
                'User downloaded order #' . $order->id,
                Order::class,
                $order->id,
            );
            switch ($order->type) {
                case 'in':
                    return $this->downloadOrderIn($id);
                case 'out':
                case 'cost_out':
                    return $this->downloadOrderOut($id);
                case 'cost_in':
                    return $this->downloadCostOrderIn($id);
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
        dd(2);
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
        $passportDate = $client?->passport_validity
            ? Carbon::parse($client->passport_validity)->format('d.m.Y')
            : null;
        $basis = trim(implode('', [
            $client?->passport_series ?? '',
            $passportDate ? ', տրվ. ' . $passportDate . 'թ. ' : '',
            $client?->passport_issued ?? '',
        ]));

        $templateProcessor->setValues([
            'amount1' => $amount1,
            'amount2' => $this->makeMoney($order->amount),
            'rep_id' => 2211,
            'num' => $contract->num ?? null,
            'order' => $order->order,
            'date' => isset($order->date) ? $this->formatArmenianDate($order->date) : null,
            'receiver' => $order->receiver,
            'client' => $client ? ($client->type === 'legal' ? $client->company_name : $client->name . ' ' . $client->surname . ' ' . ($client->middle_name ?? '')) : null,
            'contract_id' => $contract->num ?? null,
            'purpose' => $order->purpose,
            'amount1_text' => $this->numberToText((float) str_replace([' ', ',','.'], ['', '',''], $amount1)),
            'amount2_text' => $this->numberToText($order->amount),
            'phone' => $client?->phone,
            'executor' => $user ? $user->name . ' ' . $user->surname : null,
            'basis' => $basis ?: null,

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
        $order = Order::where('id', $id)->with('client')->first();
        $contract = Contract::where('id', $order->contract_id)->with('client')->first();
        $client = $order->client_id ? $order->client : $contract?->client;
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
            'num' => $order->num ?? $contract?->num ?? null,
            'client' => $client ? ($client->type === 'legal' ? $client->company_name : $client->name . ' ' . $client->surname . ' ' . ($client->middle_name ?? '')) : null,
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

    public function downloadSchedule($id)
    {
        $contract = Contract::with(['client', 'payments'])->findOrFail($id);
        $client   = $contract->client;

        $templatePath = public_path('files/gravatoms_schedule.docx');
        if (!file_exists($templatePath)) {
            abort(404, 'Template not found: gravatoms_schedule.docx');
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        $clientName = $client->name . ' ' . $client->surname
            . ($client->middle_name ? ' ' . $client->middle_name : '');

        $templateProcessor->setValues([
            'num'            => $contract->num,
            'client'         => $clientName,
            'passport_series'=> $client->passport_series ?? '',
            'city'           => $client->actual_province ?? $client->city ?? '',
            'street'         => $client->actual_street_building ?? $client->street ?? '',
            'phone'          => $client->phone ?? $contract->additional_phone ?? '',
            'bank_name'      => $client->bank_name ?? '',
            'account_number' => $client->account_number ?? '',
            'card_number'    => $client->card_number ?? '',
        ]);

        $paymentRows = [];
        foreach ($contract->payments as $p) {
            $paymentRows[] = [
                'p_d' => \Carbon\Carbon::parse($p->date)->format('d.m.Y'),
                'p_m' => $this->makeMoney((int) $p->principal_payment),
                'p_i' => $this->makeMoney((int) $p->interest_payment),
                'p_a' => $this->makeMoney((int) $p->amount),
                'p_r' => $this->makeMoney((int) $p->remaining),
            ];
        }

        if (!empty($paymentRows)) {
            $templateProcessor->cloneRowAndSetValues('p_d', $paymentRows);
        }

        $filename = storage_path('app/tmp/' . $contract->num . '_վճարման_գրաֆիկ.docx');
        if (!file_exists(dirname($filename))) {
            mkdir(dirname($filename), 0775, true);
        }
        $templateProcessor->saveAs($filename);

        $this->activity->log(
            'download_gravatoms_schedule',
            'Contract #' . $contract->num . ' վճարման գրաֆիկ',
            Contract::class,
            $contract->id
        );

        return response()->download($filename, $contract->num . '_վճարման_գրաֆիկ.docx')->deleteFileAfterSend(true);
    }

}
