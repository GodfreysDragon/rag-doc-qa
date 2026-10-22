<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AskQuestionRequest extends FormRequest
{
    /**
     * Single-user demo app: anyone who can reach it may ask.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:40'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:20000'],
            'document_ids' => ['sometimes', 'nullable', 'array'],
            'document_ids.*' => ['integer', 'exists:documents,id'],
        ];
    }

    /**
     * @return list<int>|null
     */
    public function documentIds(): ?array
    {
        $ids = $this->validated('document_ids');

        return empty($ids) ? null : array_values(array_map('intval', $ids));
    }
}
