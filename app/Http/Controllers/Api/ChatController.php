<?php

namespace App\Http\Controllers\Api;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskQuestionRequest;
use App\Services\Rag\DocumentChat;
use App\Services\Rag\TokenBudget;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    /**
     * Answer a question over the user's documents as a Server-Sent Events
     * stream: `query` → `sources` → many `text` → `done` (or `error`).
     * Refused with 429 when the question's worst-case cost doesn't fit in
     * what's left of the daily token budget.
     */
    public function __invoke(AskQuestionRequest $request, DocumentChat $chat, TokenBudget $budget): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        $question = $request->validated('question');
        $history = $request->validated('history', []);
        $documentIds = $request->documentIds();
        $reserved = $chat->tokenCeiling($question, $history);

        if (! $budget->reserve($user, $reserved)) {
            return response()->json([
                'message' => 'The daily usage limit has been reached. Please try again tomorrow.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return response()->stream(function () use ($chat, $budget, $user, $question, $history, $documentIds, $reserved): void {
            $settled = false;

            try {
                foreach ($chat->answer($question, $history, $documentIds) as $event) {
                    if ($event['type'] === 'done') {
                        $budget->settle($user, $reserved, ($event['usage']['input_tokens'] ?? 0) + ($event['usage']['output_tokens'] ?? 0));
                        $settled = true;
                    }

                    $this->send($event);

                    // A client that disconnects mid-answer keeps its whole reservation, since the real usage is unknown.
                    if (connection_aborted()) {
                        return;
                    }
                }
            } catch (Throwable $exception) {
                Log::error('Chat stream failed.', ['exception' => $exception]);

                // Most failures happen before the model produces anything, so the reservation is released.
                if (! $settled) {
                    $budget->settle($user, $reserved, 0);
                }

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
