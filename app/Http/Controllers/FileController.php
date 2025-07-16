<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Order;
use App\Traits\CalculationTrait;
use App\Traits\FileTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;
use ZipStream\File;

class FileController extends Controller
{
    use CalculationTrait;
    public function downloadContractOld($id)
    {
        $contract = Contract::where('id', $id)
            ->with(['client', 'items.category', 'pawnshop', 'payments'])
            ->firstOrFail();

        $hasCar = $contract->items->contains(fn($item) => $item->category->name === 'car');

        $filesToZip = [];

        // 1️⃣ Generate Contract Document
        $templateFile = $hasCar ? 'contract_bond_car_template.docx' : 'contract_bond_template.docx';
        $templateProcessor = new TemplateProcessor(public_path('/files/' . $templateFile));

        $pawnshop = $contract->pawnshop;
        $client = $contract->client;

        $client_name = $client->name . ' ' . $client->surname . ' ' . ($client->middle_name ?? '');
        $client_numbers = $client->phone;
        if ($client->additional_phone) {
            $client_numbers .= ', ' . $client->additional_phone;
        }

        $pawnshop_numbers = $pawnshop->telephone;
        if ($pawnshop->phone1) {
            $pawnshop_numbers .= ', ' . $pawnshop->phone1;
        }
        if ($pawnshop->phone2) {
            $pawnshop_numbers .= ', ' . $pawnshop->phone2;
        }

        $yearly_rate = $contract?->category?->name == 'electronics' ? 158.39 : $contract->interest_rate * 365;
        $cash = $contract->provided_amount > 20000 ? 'անկանխիկ' : 'կանխիկ';
        $o_t_p = $contract->provided_amount >= 400000 ? '2' : '2,5';

        $rate_percentage = 0;
        if ($contract->estimated_amount > 0) {
            $rate_percentage = ($contract->provided_amount / $contract->estimated_amount) * 100;
            $rate_percentage = round($rate_percentage, 2);
        }

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
            'client_address' => ($client->country === 'Armenia' ? 'Հայաստան' : $client->country)
                . ', ' . $client->city . ', ' . $client->street,
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

        foreach ($contract->items as $item) {
            if ($item->category->name === 'car') {
                $itemName = $item->category->title . ($item->model ? ', ' . $item->model : '') . ($contract->description ? '. ' . $contract->description : '');
                $car_values = [
                    'item' => $itemName,
                    'desc' => $contract->description,
                    'i_c' => $item->car_make,
                    'i_m' => $item->model,
                    'i_man' => $item->manufacture,
                    'i_col' => $item->color,
                    'i_l' => $item->license_plate,
                    'i_i' => $item->identification,
                    'i_p' => $item->power,
                    'i_r' => $item->registration,
                    'i_o' => $item->ownership,
                    'i_iss' => $item->issued_by,
                    'i_d' => Carbon::parse($item->date_of_issuance)->format('d.m.Y'),
                    'price' => $item->estimated_amount ? $this->makeMoney((int)$item->estimated_amount) : $this->makeMoney((int)$contract->estimated_amount),
                ];
            } else {
                $itemName = $item->category->title . ($item->subcategory ? ', ' . $item->subcategory : '')
                    . ($item->model ? ', ' . $item->model : '') . ($item->sn ? ', ' . $item->sn : '')
                    . ($item->imei ? ', ' . $item->imei : '') . ($contract->description ? '. ' . $contract->description : '');
                $table_values[] = [
                    'item' => $itemName,
                    'desc' => $contract->description,
                    'i_t' => $item->hallmark,
                    'i_w' => $item->weight,
                    'i_cw' => $item->clear_weight,
                    'price' => $item->estimated_amount ? $this->makeMoney((int)$item->estimated_amount) : $this->makeMoney((int)$contract->estimated_amount),
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
        $payments = !empty($contract->payment_schedule) && is_array($contract->payment_schedule)
            ? $contract->payment_schedule
            : $contract->payments;

        foreach ($payments as $payment) {
            $payment_values[] = [
                'p_n' => $i . '.',
                'p_d' => Carbon::parse(is_array($payment) ? $payment['date'] : $payment->date)->format('d.m.Y'),
                'p_m' => is_array($payment) ? $payment['amount'] : $payment->amount,
                'p_text' => $this->numberToText(is_array($payment) ? $payment['amount'] : $payment->amount)
            ];
            $i++;
        }
        $templateProcessor->cloneRowAndSetValues('p_n', $payment_values);

        $contractFileName = 'contract_' . $contract->num . '.docx';
        $contractPath = public_path('/files/download/' . $contractFileName);
        $templateProcessor->saveAs($contractPath);
        $filesToZip[] = $contractPath;

        // 2️⃣ Generate Car Act if Car
        if ($hasCar) {
            $car = $contract->items->firstWhere(fn($item) => $item->category->name === 'car');
            $carActTemplate = new TemplateProcessor(public_path('/files/car_act.docx'));

            $carActTemplate->setValues([
                'date' => Carbon::parse($contract->date)->format('d.m.Y'),
                'name' => $client->name,
                'surname' => $client->surname,
                'middle_name' => $client->middle_name ?? '',
                'passport' => $client->passport_series,
                'validity' => $client->passport_validity,
                'issued' => $client->passport_issued,
                'city' => $client->city,
                'street' => $client->street,
                'contract_num' => $contract->num,
                'car_model' => $car->model,
                'license_plate' => $car->license_plate,
            ]);

            $carActFileName = 'car_act_' . $contract->num . '.docx';
            $carActPath = public_path('/files/download/' . $carActFileName);
            $carActTemplate->saveAs($carActPath);
            $filesToZip[] = $carActPath;
        }

        // 3️⃣ Create Zip Archive
        $zipFileName = $contract->num . '_Պայմանագիր.zip';
        $zipFilePath = public_path('/files/download/' . $zipFileName);

        $zip = new ZipArchive;
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($filesToZip as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        } else {
            return response()->json(['error' => 'Could not create ZIP archive.'], 500);
        }

        foreach ($filesToZip as $file) {
            unlink($file);
        }

        return response()->download($zipFilePath, $zipFileName)->deleteFileAfterSend(true);
    }

    public function downloadContract($id)
    {
        $contract = Contract::where('id', $id)
            ->with(['client', 'items', 'pawnshop', 'payments'])
            ->firstOrFail();
        $hasCar = $contract->items->contains(function ($item) {
            return $item->category->name === 'car';
        });
        $templateFile = $hasCar ? 'contract_bond_car_template.docx' : 'contract_bond_template.docx';
        $templateProcessor = new TemplateProcessor(public_path('/files/' . $templateFile));        $pawnshop = $contract->pawnshop;

        $client = $contract->client;
        $client_name = $client->name . ' ' . $client->surname . ' ' . ($client->middle_name ?? '');
        $client_numbers = $client->phone;
        if ($client->additional_phone) {
            $client_numbers .= ', ' . $client->additional_phone;
        }  $pawnshop_numbers = $pawnshop->telephone;
        if ($pawnshop->phone1) {
            $pawnshop_numbers .= ', ' . $pawnshop->phone1;
        }
        if ($pawnshop->phone2) {
            $pawnshop_numbers .= ', ' . $pawnshop->phone2;
        }

        $yearly_rate = $contract?->category?->name == 'electronics' ? 158.39 : $contract->interest_rate * 365;
        $cash = $contract->provided_amount > 20000 ? 'անկանխիկ' : 'կանխիկ';
        $o_t_p = $contract->provided_amount >= 400000 ? '2' : '2,5';
        $rate_percentage = 0;

        if ($contract->estimated_amount > 0) {
            $rate_percentage = ($contract->provided_amount / $contract->estimated_amount) * 100;
            $rate_percentage = round($rate_percentage, 2);
        }

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
            'client_address' => ($client->country === 'Armenia' ? 'Հայաստան' : $client->country)
                . ', ' . $client->city . ', ' . $client->street,
            'client_numbers' => $client_numbers,
            'given' => $this->makeMoney((int) $contract->provided_amount),
            'rate_percentage' => $rate_percentage,
            'given_text' => $this->numberToText($contract->mother),
            'contract_id' => $contract->num,
            'deadline' => Carbon::parse($contract->deadline)->format('d.m.Y'),
//            'dl_ds' => Carbon::parse($contract->deadline)->diffInDays(Carbon::parse($contract->date )),
            'dl_ds' => Carbon::parse($contract->deadline)->diffInDays(Carbon::parse($contract->date)), //should include start date
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
        // Set values for the bond section
        $table_values = [];
//        foreach ($contract->items as $item) {
//            $table_values[] = [
//                'item_description' => $item->category->title . ',' . $item->subcategory .
//                    ($item->model ? ',' . $item->model : ''),
//                'i_t' => $item->hallmark,
//                'i_w' => $item->weight,
//                'i_cw' => $item->clear_weight
//            ];
//        }
        $table_values = [];
        $car_values = [];

        foreach ($contract->items as $item) {
            if ($item->category->name === 'car') {
                $itemName =  $item->category->title . ($item->model ? ', ' . $item->model : '') . ($contract->description ? '. ' . $contract->description : '');
                $car_values = [
                    'item' => $itemName,
                    'desc' => $contract->description,
                    'i_c' => $item->car_make,
                    'i_m' => $item->model,
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
        //$templateProcessor->cloneRowAndSetValues('item_description', $table_values);

//        $payment_values = [];
//        $i = 1;
//        foreach ($contract->payments as $payment) {
//            $payment_values[] = [
//                'p_n' => $i . '.',
//                'p_d' => Carbon::parse($payment->date)->format('d.m.Y'),
//                'p_m' => $payment->amount,
//                'p_text' => $this->numberToText($payment->amount)
//            ];
//            $i++;
//        }
//        $templateProcessor->cloneRowAndSetValues('p_n', $payment_values);
        $payment_values = [];
        $i = 1;

        if (!empty($contract->payment_schedule) && is_array($contract->payment_schedule)) {
            foreach ($contract->payment_schedule as $payment) {
                $payment_values[] = [
                    'p_n' => $i . '.',
                    'p_d' => Carbon::parse($payment['date'])->format('d.m.Y'),
                    'p_m' => $payment['amount'],
                    'p_text' => $this->numberToText($payment['amount'])
                ];
                $i++;
            }
        } else {
            foreach ($contract->payments as $payment) {
                $payment_values[] = [
                    'p_n' => $i . '.',
                    'p_d' => Carbon::parse($payment->date)->format('d.m.Y'),
                    'p_m' => $payment->amount,
                    'p_text' => $this->numberToText($payment->amount)
                ];
                $i++;
            }
        }

        $templateProcessor->cloneRowAndSetValues('p_n', $payment_values);

        $filename = time() . 'contract_bond.docx';
        $pathToSave = public_path('/files/download/' . $filename);
        $templateProcessor->saveAs($pathToSave);
        $downloadName = $contract->num . '_Գրավատոմս_և_Պայմանագիր.docx';
        $headers = [
            'Content-Type' => 'application/vnd.malformations-office document.multiprocessing.document',
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
        ];
        return response()->download($pathToSave, $downloadName)->deleteFileAfterSend(true);

        //return response()->file($pathToSave, $headers)->deleteFileAfterSend(true);
    }

    public function downloadAllFiles($id)
    {
        $contract = Contract::with(['client', 'items.category', 'pawnshop', 'payments'])->findOrFail($id);

        $hasCar = $contract->items->contains(fn($item) => $item->category->name === 'car');

        $client = $contract->client;
        $pawnshop = $contract->pawnshop;

        $filesToZip = [];

        // 1️⃣ Պայմանագրի ֆայլ
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
            'client_address' => ($client->country === 'Armenia' ? 'Հայաստան' : $client->country) . ', ' . $client->                                                         city . ', ' . $client->street,
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
                    'i_c' => $item->car_make,
                    'i_m' => $item->model,
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

        // 2️⃣ Ակտի ֆայլ (եթե կա մեքենա)
        if ($hasCar && $car) {
            $actTemplate = new TemplateProcessor(public_path('files/car_act.docx'));
            $actTemplate->setValues([
                'date' => Carbon::parse($contract->date)->format('d.m.Y'),
                'full_name' => $client_name,
                'passport' => $client->passport_series,
                'validity' => Carbon::parse($client->passport_validity)->format('d.m.Y') . 'թ․',
                'issued' => 'տրվ.' . $client->passport_issued,
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
        }

        // 3️⃣ ZIP ֆայլ ստեղծել
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

        // Ջնջել .docx-երը
        foreach ($filesToZip as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Վերբեռնում ենք ZIP ֆայլը
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

        if ($order) {
//            if ($order->purpose === Contract::CONTRACT_OPENING) {
//                return $this->downloadContract($order->contract_id);
//            }
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

    public function downloadOrderOutOld($id)
    {
        $templateProcessor = new TemplateProcessor(public_path('/files/contract_order_out_template.docx'));
        $order = Order::where('id', $id)->first();
        $contract = Contract::where('id', $order->contract_id)->first();
        $templateProcessor->setValues([
            'amount' => $this->makeMoney($order->amount),
            'rep_id' => $order->rep_id,
            'order' => $order->order,
            'date' => Carbon::parse($order->date)->format('d.m.Y'),
            'receiver' => $order->receiver,
            'contract_id' => $contract->ADB_ID,
            'cl_dob' => $contract->dob,
            'cl_pas' => $contract->passport,
            'cl_giv' => $contract->passport_given,
            'amount_text' => $this->numberToText($order->amount),
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

    public function downloadCostOrderOut($id)
    {
        $templateProcessor = new TemplateProcessor(public_path('/files/cost_out_template.docx'));
        $order = Order::where('id', $id)->first();
        $templateProcessor->setValues([
            'amount' => $this->makeMoney($order->amount),
            'receiver' => $order->receiver,
            'order' => $order->order,
            'date' => Carbon::parse($order->date)->format('d.m.Y'),
            'purpose' => $order->purpose,
            'amount_text' => $this->numberToText($order->amount),
        ]);
        $filename = time() . 'cost_order_out.docx';
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
    public function downloadAllFilesOld($id)
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

            // If contract has orders, add order documents
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
//    public function downloadCostOrderInOld($id)
//    {
//        $templateProcessor = new TemplateProcessor(public_path('/files/cost_in_template.docx'));
//        $order = Order::where('id', $id)->first();
//        $templateProcessor->setValues([
//            'amount' => $this->makeMoney($order->amount),
//            'receiver' => $order->receiver,
//            'order' => $order->order,
//            'date' => $order->date,
//            'purpose' => $order->purpose,
//            'amount_text' => $this->numberToText($order->amount),
//        ]);
//        $filename = time() . 'cost_order_in.docx';
//        $pathToSave = public_path('/files/download/' . $filename);
//        $templateProcessor->saveAs($pathToSave);
//        $downloadName = $order->order . ' Ծախս.docx';
//        $headers = [
//            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
//            'Content-Disposition' => 'attachment; filename=' . $downloadName,
//        ];
//        // Return the document as a response and delete the temporary file after sending
//        return response()->file($pathToSave, $headers)->deleteFileAfterSend(true);
//    }
}
