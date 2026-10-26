<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DocumentResource::collection($request->user()->documents()->latest('id')->get());
    }

    /**
     * Store the upload and queue it for chunking + embedding. Uploading the
     * same bytes twice returns the user's existing document instead of
     * re-indexing.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = $request->user()->documents()->where('content_hash', $hash)->first();

        if ($existing !== null) {
            return DocumentResource::make($existing)
                ->additional(['duplicate' => true])
                ->response()
                ->setStatusCode(Response::HTTP_OK);
        }

        $originalName = $file->getClientOriginalName();

        $document = $request->user()->documents()->create([
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
        Gate::authorize('manage', $document);

        return DocumentResource::make($document);
    }

    public function reprocess(Document $document): DocumentResource
    {
        Gate::authorize('manage', $document);

        $document->update(['status' => DocumentStatus::Pending, 'error' => null]);

        ProcessDocument::dispatch($document);

        return DocumentResource::make($document->refresh());
    }

    public function destroy(Document $document): Response
    {
        Gate::authorize('manage', $document);

        Storage::disk('local')->delete($document->path);

        $document->chunks()->delete();
        $document->delete();

        return response()->noContent();
    }
}
