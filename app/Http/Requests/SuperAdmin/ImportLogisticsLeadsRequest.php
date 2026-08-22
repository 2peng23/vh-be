<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class ImportLogisticsLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'source_file' => ['nullable', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.email' => ['nullable', 'email', 'max:255'],
            'rows.*.phone' => ['nullable', 'string', 'max:80'],
            'rows.*.source_file' => ['nullable', 'string', 'max:255'],
        ];
    }
}
