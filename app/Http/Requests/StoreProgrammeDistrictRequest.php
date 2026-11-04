<?php

namespace App\Http\Requests;

use App\Models\District;
use Illuminate\Foundation\Http\FormRequest;

class StoreProgrammeDistrictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', District::class);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'province' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'province.required' => 'Indique a província e o distrito.',
            'district.required' => 'Indique a província e o distrito.',
            '*.max' => 'O nome não pode ter mais de 100 caracteres.',
        ];
    }
}
