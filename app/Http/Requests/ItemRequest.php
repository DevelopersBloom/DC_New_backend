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
    public function rules()
    {
        return [
            'category_id' => 'required|exists:categories,id',
            'subcategory' => 'required|string|max:255',
            'model'       => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'category_id.required' => 'Category is required.',
            'category_id.exists'   => 'The selected category does not exist.',

            'subcategory.required'  => 'Subcategory is required.',
            'subcategory.string'    => 'Subcategory must be a string.',
            'subcategory.max'       => 'Subcategory must not exceed 255 characters.',

            'model.string'          => 'Model must be a string.',
            'model.max'             => 'Model must not exceed 255 characters.',
        ];
    }
}
