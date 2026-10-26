<?php

namespace App\Services\Rag\Llm;

use Anthropic\Beta\Messages\BetaFallbackBlock;
use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawContentBlockStartEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Generator;

/**
 * Claude via the official Anthropic PHP SDK.
 *
 * Uses the beta Messages endpoint so the request can opt into server-side
 * fallbacks: if the model's safety classifiers decline a request, the API
 * retries it on Anthropic's recommended fallback model rather than returning
 * an empty refusal.
 */
class ClaudeChatModel implements ChatModel
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly string $effort = 'low',
        private readonly int $maxTokens = 16000,
    ) {}

    public function stream(string $system, array $messages): Generator
    {
        $stream = $this->client->beta->messages->createStream(
            maxTokens: $this->maxTokens,
            messages: $messages,
            model: $this->model,
            system: $system,
            outputConfig: ['effort' => $this->effort],
            fallbacks: 'default',
            betas: [self::FALLBACK_BETA],
        );

        $model = $this->model;
        $stopReason = null;
        $inputTokens = 0;
        $outputTokens = 0;
        $hasText = false;

        foreach ($stream as $event) {
            if ($event instanceof BetaRawMessageStartEvent) {
                $model = $event->message->model;
                $inputTokens += $event->message->usage->inputTokens;
            } elseif ($event instanceof BetaRawContentBlockStartEvent && $event->contentBlock instanceof BetaFallbackBlock) {
                // Another model is taking over; anything streamed so far was a declined partial answer.
                if ($hasText) {
                    yield ['type' => 'reset'];
                    $hasText = false;
                }
            } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                $hasText = true;
                yield ['type' => 'text', 'text' => $event->delta->text];
            } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                $stopReason = $event->delta->stopReason;
                $outputTokens = $event->usage->outputTokens;
            }
        }

        yield [
            'type' => 'done',
            'stop_reason' => $stopReason,
            'model' => $model,
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    public function withModel(string $model): ChatModel
    {
        return new self($this->client, $model, $this->effort, $this->maxTokens);
    }

    public function complete(string $system, array $messages, int $maxTokens = 2000): string
    {
        $message = $this->client->beta->messages->create(
            maxTokens: $maxTokens,
            messages: $messages,
            model: $this->model,
            system: $system,
            outputConfig: ['effort' => 'low'],
            fallbacks: 'default',
            betas: [self::FALLBACK_BETA],
        );

        if ($message->stopReason === 'refusal') {
            return '';
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text);
    }
}
