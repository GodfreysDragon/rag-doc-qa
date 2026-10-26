<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Chunks are measured in characters (roughly 4 chars per token for English).
    | ~1200 chars ≈ 300 tokens: small enough that one chunk is about a single
    | idea, large enough to carry its own context. Overlap keeps sentences that
    | straddle a boundary retrievable from either side.
    |
    */

    'chunking' => [
        'size' => (int) env('RAG_CHUNK_SIZE', 1200),
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | provider: "voyage" (hosted, Anthropic's recommended embedding partner),
    |           "ollama" (local, free), or "hash" (offline feature-hashing
    |           baseline — lexical only, useful for demos and tests).
    |
    | The dimension is baked into the pgvector column, so changing provider or
    | model means re-running migrations and `php artisan rag:reindex`.
    |
    */

    'embeddings' => [
        'provider' => env('EMBEDDING_PROVIDER', 'voyage'),
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1024),
        'batch_size' => (int) env('EMBEDDING_BATCH_SIZE', 64),

        'voyage' => [
            'api_key' => env('VOYAGE_API_KEY'),
            'model' => env('VOYAGE_MODEL', 'voyage-3.5'),
            'url' => env('VOYAGE_URL', 'https://api.voyageai.com/v1/embeddings'),
        ],

        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
            // nomic-embed-text is trained with task prefixes; other models may not need them.
            'document_prefix' => env('OLLAMA_DOCUMENT_PREFIX', 'search_document: '),
            'query_prefix' => env('OLLAMA_QUERY_PREFIX', 'search_query: '),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | Hybrid retrieval runs a vector (semantic) search and a keyword (lexical)
    | search, then fuses the two rankings. Keyword search rescues exact terms —
    | product codes, names, acronyms — that dense embeddings tend to blur.
    |
    | fusion: "weighted" scales each ranking's scores to 0–1 and adds them as
    |         vector_weight · vector + (1 − vector_weight) · keyword.
    |         "rrf" is Reciprocal Rank Fusion with constant rrf_k.
    | On the golden set, weighted fusion with 0.6 beat RRF with both the hash
    | and nomic-embed-text embeddings (see the README).
    |
    */

    'retrieval' => [
        'top_k' => (int) env('RAG_TOP_K', 6),
        'candidates' => (int) env('RAG_CANDIDATES', 20),
        'hybrid' => (bool) env('RAG_HYBRID', true),
        'fusion' => env('RAG_FUSION', 'weighted'),
        'vector_weight' => (float) env('RAG_VECTOR_WEIGHT', 0.6),
        'rrf_k' => 60,
        // Minimum cosine similarity for a vector hit to be considered at all.
        'min_score' => (float) env('RAG_MIN_SCORE', 0.0),
        // Rewrite follow-up questions into standalone queries before retrieval.
        'rewrite_queries' => (bool) env('RAG_REWRITE_QUERIES', true),
        // How many previous turns are sent to the LLM as conversation history.
        'history_turns' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM
    |--------------------------------------------------------------------------
    */

    'llm' => [
        'provider' => env('LLM_PROVIDER', 'anthropic'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'low'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation
    |--------------------------------------------------------------------------
    |
    | rag:eval-answers grades every answer with an LLM judge. A different model
    | from the one that wrote the answers is less likely to favour its own
    | style. Leave it empty to judge with rag.llm.model.
    |
    */

    'evaluation' => [
        'judge_model' => env('RAG_JUDGE_MODEL', 'claude-sonnet-5-5'),
    ],

    'uploads' => [
        'max_kb' => (int) env('RAG_MAX_UPLOAD_KB', 20480),
        'extensions' => ['pdf', 'docx', 'txt', 'md'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounts and usage limits
    |--------------------------------------------------------------------------
    |
    | Every document belongs to a user and every search is scoped to the
    | signed-in user's documents. The limits keep an open deployment from
    | running up an unbounded LLM bill; 0 means unlimited.
    |
    */

    'auth' => [
        'registration' => (bool) env('DOCQA_REGISTRATION', true),
    ],

    'limits' => [
        'uploads_per_hour' => (int) env('RAG_UPLOADS_PER_HOUR', 30),
        'questions_per_minute' => (int) env('RAG_QUESTIONS_PER_MINUTE', 30),
        // LLM tokens (input + output, as reported by the model) per calendar day.
        'daily_tokens_per_user' => (int) env('RAG_DAILY_TOKENS_PER_USER', 300000),
        'daily_tokens_total' => (int) env('RAG_DAILY_TOKENS_TOTAL', 3000000),
    ],

];
