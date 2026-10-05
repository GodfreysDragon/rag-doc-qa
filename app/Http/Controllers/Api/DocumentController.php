<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DocumentResource::collection(Document::query()->latest('id')->get());
    }

    /**
     * Store the upload and queue it for chunking + embedding. Uploading the
     * same bytes twice returns the existing document instead of re-indexing.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());

        $existing = Document::query()->where('content_hash', $hash)->first();

        if ($existing !== null) {
            return DocumentResource::make($existing)
                ->additional(['duplicate' => true])
                ->response()
                ->setStatusCode(Response::HTTP_OK);
        }

        $originalName = $file->getClientOriginalName();

        $document = Document::create([
            'title' => $request->validated('title') ?: str(pathinfo($originalName, PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish()->toString(),
            'original_name' => $originalName,
            'path' => $file->store('documents', 'local'),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'content_hash' => $hash,
            'status' => DocumentStatus::Pending,
        ]);

        ProcessDocument::dispatch($document);

        return DocumentResource::make($document->refresh())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Document $document): DocumentResource
    {
        return DocumentResource::make($document);
    }

    public function reprocess(Document $document): DocumentResource
    {
        $document->update(['status' => DocumentStatus::Pending, 'error' => null]);

        ProcessDocument::dispatch($document);

        return DocumentResource::make($document->refresh());
    }

    public function destroy(Document $document): Response
    {
        Storage::disk('local')->delete($document->path);

        $document->chunks()->delete();
        $document->delete();

        return response()->noContent();
    }
}
