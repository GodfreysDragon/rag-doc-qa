# DocQA: RAG document Q&A

[![tests](https://github.com/GodfreysDragon/rag-doc-qa/actions/workflows/tests.yml/badge.svg)](https://github.com/GodfreysDragon/rag-doc-qa/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Upload PDFs, Word documents, Markdown or text files and ask questions about them. Answers stream back with inline citations that link to the exact passages they came from.

![DocQA: upload a document, ask a question, get a streamed answer with citations that open the source passage](.github/demo.gif)

The retrieval pipeline is built by hand rather than with a hosted "chat with your PDF" API: text extraction, chunking, embeddings, vector + keyword search, rank fusion and prompt grounding are all in this repo.

**Stack:** Laravel 13 · PHP 8.4 · Vue 3 · Tailwind 4 · PostgreSQL + pgvector (MySQL / SQLite fallback) · Claude (Anthropic PHP SDK) · Voyage AI or Ollama embeddings

---

## How it works

```
INDEX TIME (queued job)
  upload ─► extract text per page ─► heading-aware chunking (≈300 tokens, 50 overlap)
         ─► embed chunks (input_type=document) ─► pgvector column + HNSW index
                                                 └► generated tsvector + GIN index

QUERY TIME (SSE stream)
  question ─► rewrite follow-ups into a standalone query (LLM, low effort)
           ─► embed query (input_type=query)
           ─► vector search (cosine, top 20) ─┐
           ─► keyword search (ts_rank, top 20)┴► Reciprocal Rank Fusion ─► top 6
           ─► grounded prompt with numbered <source> blocks
           ─► Claude streams an answer citing [n] ─► Vue renders clickable citations
```

### Design decisions

| Concern | Choice | Why |
|---|---|---|
| **Chunking** | Markdown sections are packed whole into chunks; a section too long for one chunk is split recursively (paragraph → line → sentence → word) with overlap, and each later piece opens with its heading trail (`Policy › Using your own car`). Chunks never cross a page | Keeps chunks semantically whole, so a chunk never ends on a heading whose text is in the next one, and a piece cut from the middle of a section still says what it is about. Every chunk has one page number to cite. |
| **What gets embedded** | `"Document: <title>\n\n<chunk>"` | A chunk that just says "the fee is 3%" is much easier to find when its vector also carries which document it came from. Only the chunk text is stored and shown. |
| **Asymmetric embeddings** | Separate `embedDocuments()` / `embedQuery()` | Retrieval models (Voyage `input_type`, nomic `search_query:` prefixes) encode short questions and long passages differently. |
| **Normalisation** | Every vector is L2-normalised before storage | Cosine similarity becomes a dot product, which the PHP fallback relies on. |
| **Vector index** | pgvector HNSW with `vector_cosine_ops` | Approximate nearest-neighbour search in SQL, so queries don't scan the whole table. |
| **Hybrid search** | Dense + full-text, merged with RRF (k=60) | Embeddings blur exact tokens (SKUs, names, acronyms) and keyword search catches them. RRF only uses ranks, so cosine scores and `ts_rank` never need calibrating against each other. |
| **Query rewriting** | Only when there's history | "What about the second one?" embeds badly on its own, so it's rewritten into a standalone query first. The first turn skips that LLM call. |
| **Grounding** | Sources in tagged blocks with ids; the system prompt requires `[n]` citations, a plain "not in the documents" when the sources don't cover it, and treats source text as data | Cuts down on hallucination and prompt injection from uploaded files, and makes every claim checkable in the UI. |
| **Citation highlighting** | Clicking `[n]` matches the claim before it against the sentences of source `n` (shared terms, numbers weighted double) and highlights the best match | A citation points at a whole ~300-token chunk; this narrows it to the sentence a reader actually needs to check. Done client-side, so it costs no extra LLM call. |
| **No-context short-circuit** | If nothing is retrieved, a fixed reply is returned without calling the LLM | Saves cost and avoids answers made up from general knowledge. |
| **History** | Only conversation text is sent back, never old sources; it's normalised to alternating turns | Each turn retrieves fresh sources, and the request is always valid for the Messages API. |
| **Ingestion** | Queued, unique, idempotent job (chunks replaced in one transaction); bad files fail permanently instead of retrying | Uploads return instantly, and retries never leave duplicate chunks behind. |
| **Dedup** | SHA-256 of the upload, per user | Uploading the same file twice returns the existing document. |
| **Accounts** | Session-cookie auth with CSRF protection (the SPA is same-origin, so no API tokens); every document has an owner, and retrieval is always scoped to the signed-in user's documents | One user can never retrieve or cite another user's text. Other users' document ids answer 404, so they don't reveal that a document exists. |
| **Usage limits** | Per-user rate limits on uploads and questions, plus a daily LLM token budget per user and for the whole app | An open deployment can't run up an unbounded bill. Tokens are counted from the usage the model reports when an answer finishes. |
| **LLM safety** | `fallbacks: "default"` server-side fallback, handling of `refusal` stop reasons, and a `reset` event if a fallback takes over mid-stream | A classifier decline is retried on another model rather than shown to the user as an empty answer. |

### Swappable components

Everything sits behind small interfaces in [`app/Services/Rag`](app/Services/Rag) and is wired up in [`AppServiceProvider`](app/Providers/AppServiceProvider.php):

- `EmbeddingProvider`: `VoyageEmbeddingProvider`, `OllamaEmbeddingProvider`, and `HashEmbeddingProvider`, an offline feature-hashing baseline. It's lexical only, so it makes a good control to compare real embeddings against, and it lets the app and tests run without keys.
- `VectorStore`: `PgvectorStore`, which does everything in SQL, and `DatabaseVectorStore`, which stores JSON embeddings and does an exact brute-force cosine scan in PHP on MySQL/SQLite.
- `ChatModel`: `ClaudeChatModel`, plus a `FakeChatModel` used in tests.

---

## Getting started

### 1. Install

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
```

### 2. Pick a database

**PostgreSQL + pgvector (recommended)**

```bash
docker compose up -d          # pgvector/pgvector:pg17 on :5432
```

Then in `.env`, uncomment the `DB_*` pgsql block.

**Or SQLite (zero setup)**: the default `.env` works as-is with the PHP fallback store.

```bash
php artisan migrate
```

### 3. Configure models

```dotenv
ANTHROPIC_API_KEY=sk-ant-...

# Hosted embeddings
EMBEDDING_PROVIDER=voyage
VOYAGE_API_KEY=pa-...
EMBEDDING_DIMENSIONS=1024

# …or local & free (ollama pull nomic-embed-text)
# EMBEDDING_PROVIDER=ollama
# EMBEDDING_DIMENSIONS=768

# …or offline lexical baseline, no key needed
# EMBEDDING_PROVIDER=hash
```

> The embedding dimension is part of the pgvector column type. If you change the provider, model or dimension, run `php artisan migrate:fresh` and re-upload, or keep your data and run `php artisan rag:reindex`, which re-chunks and re-embeds every document.

### 4. Run

```bash
composer run dev     # web server + queue worker + Vite
```

Open http://localhost:8000 and create an account. Uploads are processed by the queue worker, so it has to be running.

Limits are set in `.env` (0 means unlimited): `RAG_UPLOADS_PER_HOUR` (30), `RAG_QUESTIONS_PER_MINUTE` (30), `RAG_DAILY_TOKENS_PER_USER` (300,000) and `RAG_DAILY_TOKENS_TOTAL` (3,000,000). Set `DOCQA_REGISTRATION=false` to stop new sign-ups.

---

## API

Every endpoint except `register` and `login` needs a signed-in session. State-changing requests send the `X-XSRF-TOKEN` header from Laravel's `XSRF-TOKEN` cookie.

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/v1/register` | Create an account and sign in (`name`, `email`, `password`, `password_confirmation`) |
| `POST` | `/api/v1/login` | Sign in (`email`, `password`) |
| `POST` | `/api/v1/logout` | Sign out |
| `GET` | `/api/v1/user` | The signed-in user, with today's token usage and limit |
| `GET` | `/api/v1/documents` | List your documents with status (`pending` → `processing` → `ready` / `failed`) |
| `POST` | `/api/v1/documents` | Upload (`multipart/form-data`, field `file`, optional `title`) |
| `GET` | `/api/v1/documents/{id}` | Show one document |
| `POST` | `/api/v1/documents/{id}/reprocess` | Re-run ingestion |
| `DELETE` | `/api/v1/documents/{id}` | Delete the document, its file and its chunks |
| `POST` | `/api/v1/chat` | Ask a question; responds with `text/event-stream`, or 429 once the daily token budget is spent |

`POST /api/v1/chat` body:

```json
{
  "question": "What's the refund window?",
  "history": [{ "role": "user", "content": "…" }, { "role": "assistant", "content": "…" }],
  "document_ids": [1, 3]
}
```

The stream emits `query` (the search query actually used) → `sources` (ranked chunks with similarity / keyword scores) → many `text` deltas → `done` (stop reason, model, token usage), or an `error` event.

---

## Commands

```bash
php artisan rag:reindex            # re-chunk + re-embed everything
php artisan rag:reindex --stale    # only documents embedded with a different model
php artisan rag:reindex --queue    # dispatch to the queue instead of running inline
php artisan rag:eval               # retrieval quality on the golden set (see below)
php artisan rag:eval --misses      # …and list the questions hybrid search missed
php artisan rag:eval-answers       # answer quality with the real LLM and an LLM judge (costs tokens)
php artisan test                   # 69 tests, no network calls
```

## Retrieval evaluation

`php artisan rag:eval` measures how often retrieval finds the right passage. The golden set in [`resources/eval`](resources/eval) has seven fictional company documents (handbook, product manuals, policies, a runbook) and 56 questions. Each question names the document and a phrase the retrieved chunk must contain, so the set doesn't depend on chunk size. The documents deliberately overlap: both product manuals have an error `E-12` that means different things, and two policies have 24-hour reporting rules.

The command indexes the corpus with the real ingestion pipeline inside a database transaction that is always rolled back, then runs every question through vector-only, keyword-only and hybrid retrieval. CI runs it on both databases with the offline `hash` embeddings. Results (k=6, 17 chunks):

| Mode | `hash` · SQLite | `hash` · pgvector | `nomic-embed-text` (Ollama) · SQLite |
|---|---|---|---|
| Vector only | 57% / 0.691 | 57% / 0.691 | 63% / 0.769 |
| Keyword only | 64% / 0.722 | 61% / 0.694 | 64% / 0.722 |
| Hybrid (RRF) | **68% / 0.759** | **68% / 0.756** | 64% / **0.773** |

Cells are Hit@1 / MRR. The Ollama column was run locally (`ollama pull nomic-embed-text`, then `EMBEDDING_PROVIDER=ollama EMBEDDING_DIMENSIONS=768 php artisan rag:eval` on a fresh database). Hit@1 by question type:

| Question type | `hash` vector | `hash` hybrid | `nomic` vector | `nomic` hybrid |
|---|---|---|---|---|
| Exact identifiers (17) | 71% | 88% | 65% | 82% |
| Shared keywords (18) | 94% | 94% | 61% | 72% |
| Paraphrases (21) | 14% | 29% | **62%** | 43% |

What this shows:

- **A semantic model fixes paraphrases.** "Will the company pay my speeding ticket?" shares no words with "Fines for traffic or parking violations are never reimbursed": `hash` ranks that passage 4th, `nomic-embed-text` ranks it 1st. Vector-only Hit@1 on paraphrases rises from 14% to 62%, and Hit@3 overall from 77% to 89%.
- **Keyword search still wins on exact terms.** `nomic` vector search finds the passage first for only 65% of identifier questions and 61% of keyword questions, against 88–89% for keyword search. That's the case for hybrid retrieval.
- **Equal-weight RRF is a trade-off with a good embedding model.** Hybrid has the best MRR and recovers identifier questions (65% → 82%), but on paraphrases the noisy keyword ranking pulls Hit@1 down from 62% to 43%. The speeding-ticket passage drops out of the hybrid top 6 altogether. Part of this is the small corpus: the 20 vector candidates cover all 17 chunks, so any chunk with a stray keyword match gets a second RRF score. Weighting the two rankings, or re-ranking the fused list, is the next thing to try (see below).
- **The evaluation caught a Postgres tokenisation bug.** The default text-search parser reads `E-417` as the word `e` plus the negative number `-417`, so a search for `417` never matched it. Keyword-only Hit@1 on exact questions was 65% (vs 88% for the PHP scorer). Indexing content with hyphens replaced by spaces raised it to 82%, and hybrid Hit@1 on Postgres from 64% to 68%.

The corpus also doubles as sample documents to upload and try the app with.

## Answer evaluation

Retrieval metrics don't say whether the final answers are right. `php artisan rag:eval-answers` runs every golden-set question through the whole pipeline (retrieval and Claude) and checks each answer two ways:

- **Deterministic checks:** do the `[n]` citations point at sources that exist, and does a cited source contain the expected passage?
- **An LLM judge**, given the question, the retrieved sources, the answer and the expected phrase: is the answer **correct**, and is every claim **faithful** to the sources?

The golden set also has 8 questions the documents don't answer ("Who is the CEO?"). For those, the right answer is to say so, which measures how often the system makes things up. The run makes two LLM calls per question, so it isn't in CI. Results with `nomic-embed-text` and `claude-opus-5-5` (64 questions, about 150k input tokens):

| Metric | Result |
|---|---|
| Correct (56 answerable) | 95% (53) |
| Faithful to the sources (all 64) | 98% |
| Cites the expected passage | 95% |
| Expected passage retrieved | 95% |
| Answers citing a source that doesn't exist | 0% |
| Says "not in the documents" when it isn't (8 unanswerable) | 100% |

What this shows:

- **Retrieval is the bottleneck, not generation.** Every wrong answer was a question whose passage wasn't retrieved, and in all three Claude said it couldn't find the answer instead of guessing. When the passage was retrieved, the answer was right 53 times out of 53.
- **No made-up answers.** All 8 unanswerable questions got "the documents don't say", and no answer cited a source that doesn't exist.
- **The one unfaithful answer** added a side remark the sources didn't support (that the mower has no Eco mode) to an otherwise correct answer.
- **A chunking issue showed up.** For "Will the company pay my speeding ticket?" the model noticed that a retrieved chunk ended at the heading "Using your own car", with the rules in the next chunk. The chunker now packs whole Markdown sections and never ends a chunk on a heading.

Caveat: the judge is the same model that wrote the answers, so it may be lenient towards its own style. A different judge model, or spot-checking verdicts by hand with `--failures`, guards against that.

## Configuration reference

All tunables are in [`config/rag.php`](config/rag.php): chunk size and overlap, `top_k`, candidate pool size, hybrid on/off, the RRF constant, minimum similarity, query rewriting, history length, model and effort.

## Limitations / next steps

- **Scanned PDFs** have no text layer. They fail with a clear message; adding OCR (e.g. Tesseract) at the extraction step would fix that.
- **Accounts are basic**: email and password only, with no email verification, password reset or roles. Documents uploaded before accounts existed have no owner, and nobody sees them until they're assigned a `user_id`.
- **Token budget granularity**: the budget is checked before an answer starts, so the last answer of the day can overshoot it. Query-rewrite calls aren't counted, because the model reports usage only for the streamed answer.
- **Re-ranking**: a cross-encoder re-ranker (e.g. Voyage `rerank-2.5`) over the fused top-20 would sharpen precision further.
- **Weighted fusion**: with `nomic-embed-text`, equal-weight RRF costs paraphrase accuracy (see the evaluation). Per-ranking weights in `Retriever::fuse()`, tuned with `rag:eval` on a bigger golden set, would let hybrid keep the vector ranking's paraphrase wins.
- **Bigger golden set**: 56 questions over 17 chunks is enough to compare modes, but too small to tune `top_k` or chunk size. A larger corpus would make Hit@k meaningful.
- **Filtered HNSW**: when scoping to a few documents on a large corpus, enable pgvector ≥ 0.8 iterative scans (`SET hnsw.iterative_scan = relaxed_order`).

## License

[MIT](LICENSE)
