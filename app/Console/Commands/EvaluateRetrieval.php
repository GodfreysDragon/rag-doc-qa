<?php

namespace App\Console\Commands;

use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Evaluation\RetrievalEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('rag:eval
    {--set= : Path to a golden-set JSON file; its corpus is read from a corpus/ folder next to it}
    {--k= : How many chunks to retrieve per question (defaults to rag.retrieval.top_k)}
    {--misses : List the questions that the configured hybrid fusion missed}
    {--json : Print the results as JSON instead of tables}')]
#[Description('Measure retrieval quality (hit rate and MRR) for vector, keyword and hybrid (RRF and weighted) search on a golden set')]
class EvaluateRetrieval extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RetrievalEvaluator $evaluator, EmbeddingProvider $embeddings): int
    {
        $setPath = $this->option('set') ?: resource_path('eval/questions.json');

        if (! is_file($setPath)) {
            $this->components->error("Golden set [{$setPath}] not found.");

            return self::FAILURE;
        }

        /** @var list<array{category: string, question: string, document: string, expect: string}> $cases */
        $cases = json_decode(file_get_contents($setPath), true, flags: JSON_THROW_ON_ERROR)['cases'];
        $corpusFiles = glob(dirname($setPath).'/corpus/*.{md,txt,pdf,docx}', GLOB_BRACE) ?: [];
        $topK = (int) ($this->option('k') ?: config('rag.retrieval.top_k'));

        ['chunk_count' => $chunkCount, 'results' => $results] = $evaluator->evaluate(
            $corpusFiles,
            $cases,
            topK: $topK,
            candidates: max($topK, (int) config('rag.retrieval.candidates')),
            rrfK: (int) config('rag.retrieval.rrf_k'),
            vectorWeight: (float) config('rag.retrieval.vector_weight'),
        );

        $summary = $this->summarise($results);

        if ($this->option('json')) {
            $this->line(json_encode([
                'embedding_model' => $embeddings->modelName(),
                'database' => DB::connection()->getDriverName(),
                'k' => $topK,
                'chunk_count' => $chunkCount,
                ...$summary,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Evaluated %d questions over %d documents (%d chunks) with [%s] on [%s], k=%d.',
            count($results),
            count($corpusFiles),
            $chunkCount,
            $embeddings->modelName(),
            DB::connection()->getDriverName(),
            $topK,
        ));

        $this->table(
            ['Mode', 'Hit@1', 'Hit@3', "Hit@{$topK}", 'MRR'],
            collect($summary['overall'])->map(fn (array $metrics, string $mode): array => [
                $mode,
                $this->percent($metrics['hit_at_1']),
                $this->percent($metrics['hit_at_3']),
                $this->percent($metrics['hit_at_k']),
                number_format($metrics['mrr'], 3),
            ])->values()->all(),
        );

        $this->newLine();
        $this->line('Hit@1 by question type:');
        $this->table(
            ['Category', 'Questions', ...RetrievalEvaluator::MODES],
            collect($summary['by_category'])->map(fn (array $category, string $name): array => [
                $name,
                $category['questions'],
                ...array_map(fn (string $mode): string => $this->percent($category['hit_at_1'][$mode]), RetrievalEvaluator::MODES),
            ])->values()->all(),
        );

        if ($this->option('misses')) {
            $fusion = config('rag.retrieval.fusion');
            $misses = array_filter($results, fn (array $result): bool => $result['ranks'][$fusion] === null);

            $this->newLine();
            $this->line(count($misses)." question(s) missed by hybrid retrieval ({$fusion}):");

            foreach ($misses as $miss) {
                $this->components->twoColumnDetail("[{$miss['category']}] {$miss['question']}", $miss['document']);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{category: string, ranks: array<string, int|null>}>  $results
     * @return array{overall: array<string, array{hit_at_1: float, hit_at_3: float, hit_at_k: float, mrr: float}>, by_category: array<string, array{questions: int, hit_at_1: array<string, float>}>}
     */
    private function summarise(array $results): array
    {
        $ranksFor = fn (array $subset, string $mode): array => array_map(fn (array $result): ?int => $result['ranks'][$mode], $subset);

        $overall = [];

        foreach (RetrievalEvaluator::MODES as $mode) {
            $overall[$mode] = RetrievalEvaluator::metrics($ranksFor($results, $mode));
        }

        $byCategory = collect($results)
            ->groupBy('category')
            ->map(fn ($subset): array => [
                'questions' => $subset->count(),
                'hit_at_1' => collect(RetrievalEvaluator::MODES)
                    ->mapWithKeys(fn (string $mode): array => [$mode => RetrievalEvaluator::metrics($ranksFor($subset->all(), $mode))['hit_at_1']])
                    ->all(),
            ])
            ->all();

        return ['overall' => $overall, 'by_category' => $byCategory];
    }

    private function percent(float $value): string
    {
        return number_format($value * 100).'%';
    }
}
