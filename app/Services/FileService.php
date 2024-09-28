<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\File;
use Illuminate\Http\UploadedFile;

class FileService
{
    public function uploadContractFiles(int $contract_id, $files,int $file_type_id)
    {
        $path = storage_path('app/public/client/files');
        $contract = Contract::findOrFail($contract_id);
        foreach ($files as $file) {
            /** @var UploadedFile $file */
        $file_name = time() . '_' . $file->getClientOriginalName();
        $file->move($path, $file_name);
        $contract->files()->create([
            'name' => $file_name,
            'type' =>  $file->getClientMimeType(),
            'original_name' => $file->getClientOriginalName(),
            'file_type_id' => $file_type_id
        ]);

        }

    }

}
