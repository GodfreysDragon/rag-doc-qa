<?php

namespace App\Console\Commands;

use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Evaluation\AnswerEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rag:eval-answers
    {--set= : Path to a golden-set JSON file; its corpus is read from a corpus/ folder next to it}
    {--limit= : Only evaluate the first N answerable and N unanswerable questions}
    {--failures : List the answers the judge marked incorrect or unfaithful}
    {--json : Print the results as JSON instead of tables}')]
#[Description('Measure answer quality (correctness, faithfulness, citations, abstention) with the real LLM and an LLM judge')]
class EvaluateAnswers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AnswerEvaluator $evaluator, EmbeddingProvider $embeddings): int
    {
        $setPath = $this->option('set') ?: resource_path('eval/questions.json');

        if (! is_file($setPath)) {
            $this->components->error("Golden set [{$setPath}] not found.");

            return self::FAILURE;
        }

        $set = json_decode(file_get_contents($setPath), true, flags: JSON_THROW_ON_ERROR);
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        /** @var list<array{category: string, question: string, document: string, expect: string}> $cases */
        $cases = array_slice($set['cases'], 0, $limit);
        /** @var list<string> $unanswerable */
        $unanswerable = array_slice($set['unanswerable'] ?? [], 0, $limit);
        $corpusFiles = glob(dirname($setPath).'/corpus/*.{md,txt,pdf,docx}', GLOB_BRACE) ?: [];

        if (! $this->option('json')) {
            $this->components->warn(sprintf(
                'This makes %d LLM calls (an answer and a judgement per question) and costs tokens.',
                2 * (count($cases) + count($unanswerable)),
            ));
        }

        ['chunk_count' => $chunkCount, 'results' => $results] = $evaluator->evaluate($corpusFiles, $cases, $unanswerable);
        $metrics = AnswerEvaluator::metrics($results);

        if ($this->option('json')) {
            $this->line(json_encode([
                'embedding_model' => $embeddings->modelName(),
                'chat_model' => config('rag.llm.model'),
                'chunk_count' => $chunkCount,
                'metrics' => $metrics,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Answered %d questions over %d documents (%d chunks) with [%s] embeddings and [%s].',
            count($results),
            count($corpusFiles),
            $chunkCount,
            $embeddings->modelName(),
            config('rag.llm.model'),
        ));

        $this->table(['Metric', 'Value', 'What it measures'], [
            ['Correct', $this->percent($metrics['correct']), "Judge: answer states the expected fact ({$metrics['answerable']} answerable)"],
            ['Faithful', $this->percent($metrics['faithful']), 'Judge: every claim is supported by the sources'],
            ['Citation accuracy', $this->percent($metrics['citation_accuracy']), 'A cited source contains the expected passage'],
            ['Retrieval hit', $this->percent($metrics['retrieval_hit']), 'The expected passage was among the sources'],
            ['Invalid citations', $this->percent($metrics['invalid_citations']), 'Answers citing a source id that does not exist'],
            ['Abstained', $this->percent($metrics['abstained']), "Judge: said the documents don't cover it ({$metrics['unanswerable']} unanswerable)"],
        ]);

        $this->line(sprintf(
            'Answer tokens: %s in / %s out. Judge errors: %d.',
            number_format($metrics['input_tokens']),
            number_format($metrics['output_tokens']),
            $metrics['judge_errors'],
        ));

        $this->newLine();
        $this->line('Correct by question type:');
        $this->table(
            ['Category', 'Questions', 'Correct', 'Citation accuracy'],
            collect($results)
                ->groupBy('category')
                ->map(function ($subset, string $category): array {
                    $metrics = AnswerEvaluator::metrics($subset->all());

                    return $category === 'unanswerable'
                        ? [$category, $subset->count(), $this->percent($metrics['abstained']).' abstained', '-']
                        : [$category, $subset->count(), $this->percent($metrics['correct']), $this->percent($metrics['citation_accuracy'])];
                })
                ->values()
                ->all(),
        );

        if ($this->option('failures')) {
            $failures = array_filter(
                $results,
                fn (array $result): bool => $result['verdict'] !== null && (! $result['verdict']['correct'] || ! $result['verdict']['faithful']),
            );

            $this->newLine();
            $this->line(count($failures).' answer(s) marked incorrect or unfaithful:');

            foreach ($failures as $failure) {
                $this->components->twoColumnDetail("[{$failure['category']}] {$failure['question']}", $failure['verdict']['reason']);
            }
        }

        return self::SUCCESS;
    }

    private function percent(float $value): string
    {
        return number_format($value * 100).'%';
    }
}
