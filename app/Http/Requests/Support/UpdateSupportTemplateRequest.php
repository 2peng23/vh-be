<?php

namespace App\Http\Requests\Support;

use App\Models\SupportTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        $supportTemplate = $this->route('supportTemplate');

        return [
            'title' => ['required', 'string', 'max:100', Rule::unique('support_templates', 'title')->ignore($supportTemplate instanceof SupportTemplate ? $supportTemplate->id : null)],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
