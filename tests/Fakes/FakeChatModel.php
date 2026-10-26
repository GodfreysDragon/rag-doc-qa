<?php

namespace Tests\Fakes;

use App\Services\Rag\Llm\ChatModel;
use Generator;

/**
 * Records every request and replies with canned text, so tests can assert on
 * exactly what the RAG pipeline sent to the LLM without any network calls.
 */
class FakeChatModel implements ChatModel
{
    /** @var list<array{system: string, messages: list<array{role: string, content: string}>}> */
    public array $streamCalls = [];

    /** @var list<array{system: string, messages: list<array{role: string, content: string}>}> */
    public array $completeCalls = [];

    /** @var list<string> Models requested through withModel(); the fake answers for all of them. */
    public array $models = [];

    public function __construct(
        public string $answer = 'The refund window is 30 days [1].',
        public string $rewrite = '',
    ) {}

    public function stream(string $system, array $messages): Generator
    {
        $this->streamCalls[] = ['system' => $system, 'messages' => $messages];

        foreach (str_split($this->answer, 8) as $piece) {
            yield ['type' => 'text', 'text' => $piece];
        }

        yield [
            'type' => 'done',
            'stop_reason' => 'end_turn',
            'model' => 'fake-model',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
        ];
    }

    public function withModel(string $model): ChatModel
    {
        $this->models[] = $model;

        return $this;
    }

    public function complete(string $system, array $messages, int $maxTokens = 2000): string
    {
        $this->completeCalls[] = ['system' => $system, 'messages' => $messages];

        return $this->rewrite;
    }
}
