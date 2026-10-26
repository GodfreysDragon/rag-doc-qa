<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\Rag\Evaluation\AnswerEvaluator;
use App\Services\Rag\Llm\ChatModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

class AnswerEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private const CASE = [
        'category' => 'exact',
        'question' => 'What does error E-417 mean?',
        'document' => 'hx2-robot-vacuum-manual.md',
        'expect' => 'E-417: Dock communication failure',
    ];

    private FakeChatModel $llm;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // With no history there's no query rewrite, so every complete() call is the judge.
        $this->llm = new FakeChatModel(
            answer: 'It means a dock communication failure [1][2][3][4][5][6][9].',
            rewrite: 'Verdict: {"correct": true, "faithful": false, "reason": "Cites a source that does not exist."}',
        );
        $this->app->instance(ChatModel::class, $this->llm);
    }

    public function test_answers_are_checked_for_citations_and_graded_by_the_judge(): void
    {
        ['results' => [$result]] = $this->evaluate([self::CASE]);

        $this->assertSame([1, 2, 3, 4, 5, 6, 9], $result['citations']);
        $this->assertSame([9], $result['invalid_citations']);
        $this->assertTrue($result['retrieved_expected']);
        $this->assertTrue($result['cited_expected']);
        $this->assertSame(['correct' => true, 'faithful' => false, 'reason' => 'Cites a source that does not exist.'], $result['verdict']);

        $judgement = $this->llm->completeCalls[0];
        $this->assertSame(AnswerEvaluator::JUDGE_PROMPT, $judgement['system']);
        $this->assertStringContainsString("<reference>\nE-417: Dock communication failure\n</reference>", $judgement['messages'][0]['content']);
        $this->assertStringContainsString('dock communication failure [1]', $judgement['messages'][0]['content']);

        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unanswerable_questions_are_graded_against_no_reference(): void
    {
        $this->llm->answer = "The documents don't say who the CEO is.";
        $this->llm->rewrite = '{"correct": true, "faithful": true, "reason": "Abstained."}';

        ['results' => [$result]] = $this->evaluate([], ['Who is the CEO of Northwind Robotics?']);

        $this->assertFalse($result['answerable']);
        $this->assertFalse($result['cited_expected']);
        $this->assertStringContainsString("<reference>\nNONE\n</reference>", $this->llm->completeCalls[0]['messages'][0]['content']);
        $this->assertSame(1.0, AnswerEvaluator::metrics([$result])['abstained']);
    }

    public function test_unparseable_judge_replies_count_as_judge_errors(): void
    {
        $this->llm->rewrite = 'I think it is mostly right.';

        ['results' => $results] = $this->evaluate([self::CASE]);

        $this->assertNull($results[0]['verdict']);
        $this->assertSame(1, AnswerEvaluator::metrics($results)['judge_errors']);
    }

    public function test_metrics_separate_answerable_and_unanswerable_questions(): void
    {
        $result = fn (bool $answerable, ?bool $correct, bool $cited, array $invalid = []): array => [
            'answerable' => $answerable,
            'verdict' => $correct === null ? null : ['correct' => $correct, 'faithful' => $correct, 'reason' => ''],
            'cited_expected' => $cited,
            'retrieved_expected' => $cited,
            'invalid_citations' => $invalid,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ];

        $metrics = AnswerEvaluator::metrics([
            $result(true, true, true),
            $result(true, false, false, [7]),
            $result(true, null, true),
            $result(false, true, false),
        ]);

        $this->assertSame(3, $metrics['answerable']);
        $this->assertSame(0.5, $metrics['correct']);
        $this->assertEqualsWithDelta(2 / 3, $metrics['citation_accuracy'], 1e-9);
        $this->assertSame(0.25, $metrics['invalid_citations']);
        $this->assertSame(1, $metrics['unanswerable']);
        $this->assertSame(1.0, $metrics['abstained']);
        $this->assertSame(1, $metrics['judge_errors']);
        $this->assertSame(40, $metrics['input_tokens']);
    }

    public function test_the_command_reports_results_as_json(): void
    {
        Artisan::call('rag:eval-answers', ['--json' => true, '--limit' => 1]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(2, $report['results']);
        $this->assertSame([true, false], array_column($report['results'], 'answerable'));
        $this->assertSame(1, $report['metrics']['answerable']);
        $this->assertSame(1, $report['metrics']['unanswerable']);
    }

    /**
     * @param  list<array{category: string, question: string, document: string, expect: string}>  $cases
     * @param  list<string>  $unanswerable
     * @return array{chunk_count: int, results: list<array<string, mixed>>}
     */
    private function evaluate(array $cases, array $unanswerable = []): array
    {
        return app(AnswerEvaluator::class)->evaluate(
            [resource_path('eval/corpus/hx2-robot-vacuum-manual.md'), resource_path('eval/corpus/m1-robot-mower-manual.md')],
            $cases,
            $unanswerable,
        );
    }
}
