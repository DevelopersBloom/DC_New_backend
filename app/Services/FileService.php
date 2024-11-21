<?php

namespace App\Services;

use App\Models\Contract;

class FileService
{
    public function uploadContractFiles(int $contract_id, array $filesData)
    {
        $path = storage_path('app/public/client/files');
        $contract = Contract::findOrFail($contract_id);

        foreach ($filesData as $fileEntry) {
            if (isset($fileEntry['file_type']) && isset($fileEntry['file'])) {
                $fileType = $fileEntry['file_type'];
                $files = $fileEntry['file'];
//                foreach ($files as $file) {
                    $fileName = time() . '_' . $fileEntry['file']->getClientOriginalName();
                    $fileEntry['file']->move($path, $fileName);

                    $contract->files()->create([
                        'name' => $fileName,
                        'type' => $fileEntry['file']->getClientMimeType(),
                        'original_name' => $fileEntry['file']->getClientOriginalName(),
                        'file_type' => $fileType,
                    ]);
//                }
            }
        }
    }
}
