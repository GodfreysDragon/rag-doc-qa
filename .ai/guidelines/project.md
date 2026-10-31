# DocQA: notes for contributors and coding agents

A RAG document Q&A app: Laravel 13 (PHP 8.4), Vue 3 with Tailwind 4, PostgreSQL + pgvector (SQLite/MySQL fallback),
Claude for answers and re-ranking, Voyage, Ollama or an offline hash provider for embeddings. `README.md` explains the
pipeline and the reasons behind each choice, with evaluation numbers. Read it before changing retrieval or prompts.

## Commands

```sh
composer run dev                 # web server, queue worker and Vite (uploads need the worker)
php artisan test                 # PHPUnit; no network calls
vendor/bin/pint                  # format PHP (CI runs vendor/bin/pint --test)
php artisan rag:eval             # retrieval quality on the tuning set; add --set=resources/eval/holdout.json, --misses
php artisan rag:reindex          # re-chunk and re-embed everything (--stale, --queue)
php artisan rag:eval-answers     # answer quality with the real LLM and a judge; costs tokens, not in CI
```

## Invariants to keep

- **Retrieval is always scoped to the asker's documents.** `VectorStore::similaritySearch()`/`keywordSearch()` treat
  `null` document ids as "every document in the table". `AskQuestionRequest::documentIds()` therefore never returns
  null (the selected ids, validated against `user_id`, or all of the user's). Any new caller must pass an explicit list.
- **Other users' documents answer 404.** Use `Gate::authorize('manage', $document)` (`DocumentPolicy` denies as not
  found); list and dedup queries go through `$user->documents()`, and the SHA-256 dedup is per user.
- **Index and query embeddings must match.** Documents use `embedDocuments()` on `"Document: <title>\n\n<chunk>"`
  (`DocumentIngestor::embeddingInput()`); only the bare chunk is stored. Queries use `embedQuery()`. Every provider
  returns L2-normalised vectors (`Vector::normalize()`), because `DatabaseVectorStore` scores by plain dot product.
  The pgvector column's dimension is fixed at migration time: a dimension change needs `migrate:fresh`, and
  `embedding_model` (what `rag:reindex --stale` compares) holds the model name, not the dimension, for Voyage/Ollama.
- **Keyword tokenisation matches on both sides.** Postgres' `search_vector` indexes `replace(content, '-', ' ')` so codes
  like `E-417` match `Keywords::extract()` terms. Keep the two in step, and keep `DatabaseVectorStore` behaving like
  `PgvectorStore` (both only search `ready` documents).
- **Ingestion stays idempotent.** `replaceDocumentChunks()` deletes and inserts a document's chunks in one transaction;
  `ProcessDocument` is unique per document and calls `fail()` on `UnprocessableDocumentException` /
  `EmbeddingConfigurationException` so bad files don't retry. Keep `$timeout` below the queue's `retry_after`.
- **Citations are positional.** Source `[n]` is the n-th chunk in the `sources` SSE event (`id = index + 1`) and the
  n-th `<source id="n">` in `DocumentChat::buildPrompt()`; the client only renders `[n]` with 1 ≤ n ≤ source count as a
  button. Don't reorder or filter sources between the event and the prompt. No sources means `NO_CONTEXT_ANSWER`, with
  no LLM call. History carries text only, never old sources.
- **Every LLM call is in the token budget.** `ChatController` reserves `DocumentChat::tokenCeiling()` with
  `TokenBudget::reserve()` before streaming and settles it with the `done` event's usage, which already sums the query
  rewrite and re-ranker usage. A new LLM call must add to both the ceiling (as `Retriever::rerankTokenCeiling()` does)
  and the reported usage, or concurrent questions can overshoot the daily limit.
- **Optional LLM steps degrade, never fail the answer**: a failed rewrite uses the raw question, a failed re-rank the
  fused order. The SSE events (`query`, `sources`, `text`, `reset` on a model fallback, `done`, `error`) are handled in
  `useChat.js`; add new types on both sides.

## Conventions

- Tests never hit the network: `phpunit.xml` sets `EMBEDDING_PROVIDER=hash` and `RAG_RERANKER=none`, tests bind
  `Tests\Fakes\FakeChatModel` to `ChatModel` and assert on its recorded calls, and Voyage is faked with `Http::fake()`.
- Retrieval defaults were tuned on `resources/eval/questions.json` only; don't tune on `holdout.json`. Rerun
  `rag:eval` on both sets after retrieval changes.
