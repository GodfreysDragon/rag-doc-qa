<?php

namespace App\Http\Controllers\Api;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskQuestionRequest;
use App\Services\Rag\DocumentChat;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    /**
     * Answer a question over the indexed documents as a Server-Sent Events
     * stream: `query` → `sources` → many `text` → `done` (or `error`).
     */
    public function __invoke(AskQuestionRequest $request, DocumentChat $chat): StreamedResponse
    {
        $question = $request->validated('question');
        $history = $request->validated('history', []);
        $documentIds = $request->documentIds();

        return response()->stream(function () use ($chat, $question, $history, $documentIds): void {
            try {
                foreach ($chat->answer($question, $history, $documentIds) as $event) {
                    $this->send($event);

                    if (connection_aborted()) {
                        return;
                    }
                }
            } catch (Throwable $exception) {
                Log::error('Chat stream failed.', ['exception' => $exception]);

                $this->send(['type' => 'error', 'message' => $this->errorMessage($exception)]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Most-specific first: retryable conditions get a "try again", config
     * problems say what to fix, and anything else is only detailed in debug mode.
     */
    private function errorMessage(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof AuthenticationException => 'The Anthropic API key is missing or invalid. Set ANTHROPIC_API_KEY in .env.',
            $exception instanceof RateLimitException => 'The model is rate limited right now. Please try again in a moment.',
            $exception instanceof APIConnectionException => 'Could not reach the Anthropic API. Check your network connection.',
            $exception instanceof RequestException => "The embedding provider returned an error ({$exception->response->status()}). Check its API key and configuration.",
            app()->hasDebugModeEnabled() => $exception->getMessage(),
            default => 'Something went wrong while generating the answer.',
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function send(array $event): void
    {
        echo 'event: '.$event['type']."\n";
        echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
