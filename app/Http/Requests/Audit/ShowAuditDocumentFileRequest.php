<?php

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowAuditDocumentFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', Rule::in(['old', 'new'])],
        ];
    }
}
