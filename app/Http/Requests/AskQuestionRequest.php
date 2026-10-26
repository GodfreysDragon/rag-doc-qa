<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskQuestionRequest extends FormRequest
{
    /**
     * The route requires a signed-in user; documents are scoped below.
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
            'document_ids.*' => ['integer', Rule::exists('documents', 'id')->where('user_id', $this->user()->id)],
        ];
    }

    /**
     * The documents to search: the selected ones, or else all of the user's.
     * Never null, so retrieval can't fall through to other users' documents.
     *
     * @return list<int>
     */
    public function documentIds(): array
    {
        $ids = $this->validated('document_ids');

        if (! empty($ids)) {
            return array_values(array_map('intval', $ids));
        }

        return $this->user()->documents()->pluck('id')->all();
    }
}
