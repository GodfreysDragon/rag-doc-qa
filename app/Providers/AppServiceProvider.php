<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Services\Rag\Chunking\TextChunker;
use App\Services\Rag\DocumentChat;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Embeddings\HashEmbeddingProvider;
use App\Services\Rag\Embeddings\OllamaEmbeddingProvider;
use App\Services\Rag\Embeddings\VoyageEmbeddingProvider;
use App\Services\Rag\Llm\ChatModel;
use App\Services\Rag\Llm\ClaudeChatModel;
use App\Services\Rag\Retrieval\Retriever;
use App\Services\Rag\TokenBudget;
use App\Services\Rag\VectorStores\DatabaseVectorStore;
use App\Services\Rag\VectorStores\PgvectorStore;
use App\Services\Rag\VectorStores\VectorStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EmbeddingProvider::class, function (): EmbeddingProvider {
            $config = config('rag.embeddings');

            return match ($config['provider']) {
                'voyage' => new VoyageEmbeddingProvider(
                    apiKey: $config['voyage']['api_key'],
                    model: $config['voyage']['model'],
                    dimensions: $config['dimensions'],
                    url: $config['voyage']['url'],
                    batchSize: $config['batch_size'],
                ),
                'ollama' => new OllamaEmbeddingProvider(
                    url: $config['ollama']['url'],
                    model: $config['ollama']['model'],
                    dimensions: $config['dimensions'],
                    documentPrefix: $config['ollama']['document_prefix'],
                    queryPrefix: $config['ollama']['query_prefix'],
                    batchSize: $config['batch_size'],
                ),
                'hash' => new HashEmbeddingProvider($config['dimensions']),
                default => throw new InvalidArgumentException("Unknown embedding provider [{$config['provider']}]."),
            };
        });

        $this->app->singleton(VectorStore::class, function (Application $app): VectorStore {
            $connection = $app['db']->connection();

            return $connection->getDriverName() === 'pgsql'
                ? new PgvectorStore($connection)
                : new DatabaseVectorStore($connection);
        });

        $this->app->singleton(ChatModel::class, fn (): ChatModel => new ClaudeChatModel(
            client: new AnthropicClient(apiKey: config('rag.llm.api_key') ?: null),
            model: config('rag.llm.model'),
            effort: config('rag.llm.effort'),
            maxTokens: config('rag.llm.max_tokens'),
        ));

        $this->app->bind(TextChunker::class, fn (): TextChunker => new TextChunker(
            size: config('rag.chunking.size'),
            overlap: config('rag.chunking.overlap'),
        ));

        $this->app->bind(Retriever::class, fn (Application $app): Retriever => new Retriever(
            embeddings: $app->make(EmbeddingProvider::class),
            store: $app->make(VectorStore::class),
            topK: config('rag.retrieval.top_k'),
            candidates: config('rag.retrieval.candidates'),
            hybrid: config('rag.retrieval.hybrid'),
            rrfK: config('rag.retrieval.rrf_k'),
            minScore: config('rag.retrieval.min_score'),
            fusion: config('rag.retrieval.fusion'),
            vectorWeight: config('rag.retrieval.vector_weight'),
        ));

        $this->app->bind(TokenBudget::class, fn (Application $app): TokenBudget => new TokenBudget(
            cache: $app->make('cache.store'),
            dailyPerUser: config('rag.limits.daily_tokens_per_user'),
            dailyTotal: config('rag.limits.daily_tokens_total'),
        ));

        $this->app->bind(DocumentChat::class, fn (Application $app): DocumentChat => new DocumentChat(
            retriever: $app->make(Retriever::class),
            llm: $app->make(ChatModel::class),
            rewriteQueries: config('rag.retrieval.rewrite_queries'),
            historyTurns: config('rag.retrieval.history_turns'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * `artisan serve` strips the environment down to an allow-list. On
         * Windows that drops TEMP/TMP, leaving PHP nowhere to write file
         * uploads ("unable to create a temporary file").
         */
        array_push(ServeCommand::$passthroughVariables, 'TEMP', 'TMP');

        RateLimiter::for('uploads', fn (Request $request): Limit => $this->perUser(
            Limit::perHour(config('rag.limits.uploads_per_hour')),
            $request,
        ));

        RateLimiter::for('questions', fn (Request $request): Limit => $this->perUser(
            Limit::perMinute(config('rag.limits.questions_per_minute')),
            $request,
        ));
    }

    /**
     * Key a limit by user (these routes require one); a limit of 0 means unlimited.
     */
    private function perUser(Limit $limit, Request $request): Limit
    {
        return $limit->maxAttempts > 0 ? $limit->by((string) $request->user()?->id) : Limit::none();
    }
}
