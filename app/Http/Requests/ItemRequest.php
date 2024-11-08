<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => 'required|array',
            'items.*.category_id' => 'required|exists:categories,id',
            'items.*.subcategory' => 'nullable|string|max:255',
            'items.*.description' => 'nullable|string|max:1000',

            // Phone-specific rules
            'items.*.model' => 'nullable|string|max:255',

            // Gold-specific rules
            'items.*.weight' => 'required_if:items.*.category.name,gold|nullable|numeric|min:0',
            'items.*.clear_weight' => 'required_if:items.*.category.name,gold|nullable|numeric|min:0',
            'items.*.hallmark' => 'nullable|string|max:255',

            // Car-specific rules
            'items.*.car_make' => 'required_if:items.*.category.name,car|nullable|string|max:255',
            'items.*.manufacture' => 'required_if:items.*.category.name,car|nullable|integer|min:1900|max:' . date('Y'),
            'items.*.power' => 'required_if:items.*.category.name,car|nullable|numeric|min:0',
            'items.*.license_plate' => 'required_if:items.*.category.name,car|nullable|string|max:20',
            'items.*.color' => 'required_if:items.*.category.name,car|nullable|string|max:50',
            'items.*.registration' => 'required_if:items.*.category.name,car|nullable|string|max:50',
            'items.*.identification' => 'required_if:items.*.category.name,car|nullable|string|max:50',
            'items.*.ownership' => 'required_if:items.*.category.name,car|nullable|string|max:50',
            'items.*.issued_by' => 'required_if:items.*.category.name,car|nullable|string|max:255',
            'items.*.date_of_issuance' => 'required_if:items.*.category.name,car|nullable|date',


        ];
    }
}
