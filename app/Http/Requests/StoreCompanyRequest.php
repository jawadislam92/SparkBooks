<?php

namespace App\Http\Requests;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'base_currency' => ['required', Rule::in(Currencies::COMMON)],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
