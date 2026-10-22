<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\Evaluation\RetrievalEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class RetrievalEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_metrics_score_hits_by_rank(): void
    {
        $metrics = RetrievalEvaluator::metrics([1, 2, null, 4]);

        $this->assertSame(0.25, $metrics['hit_at_1']);
        $this->assertSame(0.5, $metrics['hit_at_3']);
        $this->assertSame(0.75, $metrics['hit_at_k']);
        $this->assertEqualsWithDelta((1 + 1 / 2 + 1 / 4) / 4, $metrics['mrr'], 1e-9);
    }

    public function test_the_expected_passage_is_ranked_in_every_mode(): void
    {
        ['results' => $results] = app(RetrievalEvaluator::class)->evaluate(
            [resource_path('eval/corpus/hx2-robot-vacuum-manual.md'), resource_path('eval/corpus/m1-robot-mower-manual.md')],
            [['category' => 'exact', 'question' => 'What does error E-417 mean?', 'document' => 'hx2-robot-vacuum-manual.md', 'expect' => 'E-417: Dock communication failure']],
            topK: 6,
            candidates: 20,
            rrfK: 60,
        );

        foreach (RetrievalEvaluator::MODES as $mode) {
            $this->assertNotNull($results[0]['ranks'][$mode], "{$mode} retrieval missed the expected passage.");
        }
    }

    public function test_evaluation_leaves_no_documents_or_files_behind(): void
    {
        $this->artisan('rag:eval')
            ->expectsOutputToContain('Evaluated')
            ->expectsOutputToContain('hybrid')
            ->assertSuccessful();

        $this->assertSame(0, Document::count());
        $this->assertSame(0, Chunk::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_case_for_a_document_outside_the_corpus_is_rejected_and_rolled_back(): void
    {
        try {
            app(RetrievalEvaluator::class)->evaluate(
                [resource_path('eval/corpus/employee-handbook.md')],
                [['category' => 'keyword', 'question' => 'Anything?', 'document' => 'missing.md', 'expect' => 'anything']],
                topK: 6,
                candidates: 20,
                rrfK: 60,
            );
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('[missing.md]', $exception->getMessage());
        }

        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_results_can_be_exported_as_json(): void
    {
        Artisan::call('rag:eval', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $cases = json_decode(file_get_contents(resource_path('eval/questions.json')), true)['cases'];

        $this->assertSame('hash:512', $report['embedding_model']);
        $this->assertCount(count($cases), $report['results']);
        $this->assertSame(RetrievalEvaluator::MODES, array_keys($report['overall']));
        $this->assertSame(['exact', 'keyword', 'paraphrase'], array_keys($report['by_category']));
    }
}
