<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Single-user demo app: anyone who can reach it may upload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `extensions` pins the client-supplied extension (which the extractor
     * dispatches on) and `mimetypes` checks the sniffed content type. DOCX is
     * a zip container, and some libmagic builds report it as application/zip.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.config('rag.uploads.max_kb'),
                'extensions:'.implode(',', config('rag.uploads.extensions')),
                'mimetypes:'.implode(',', [
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/zip',
                    'text/plain',
                    'text/markdown',
                    'text/x-markdown',
                ]),
            ],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
