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
           ─► keyword search (ts_rank, top 20)┴► weighted score fusion ─► top 6
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
| **Hybrid search** | Dense + full-text, merged by weighted score fusion: each list's scores scaled to 0–1, then 0.6 × vector + 0.4 × keyword. RRF (k=60) is still available with `RAG_FUSION=rrf` | Embeddings blur exact tokens (SKUs, names, acronyms) and keyword search catches them. Scaling each list to 0–1 means cosine scores and `ts_rank` never need calibrating against each other. Unlike RRF, a chunk that's the clear best on one side keeps its lead (see the evaluation). |
| **Query rewriting** | Only when there's history | "What about the second one?" embeds badly on its own, so it's rewritten into a standalone query first. The first turn skips that LLM call. |
| **Grounding** | Sources in tagged blocks with ids; the system prompt requires `[n]` citations, a plain "not in the documents" when the sources don't cover it, and treats source text as data | Cuts down on hallucination and prompt injection from uploaded files, and makes every claim checkable in the UI. |
| **Citation highlighting** | Clicking `[n]` matches the claim before it against the sentences of source `n` (shared terms, numbers weighted double) and highlights the best match | A citation points at a whole ~300-token chunk; this narrows it to the sentence a reader actually needs to check. Done client-side, so it costs no extra LLM call. |
| **No-context short-circuit** | If nothing is retrieved, a fixed reply is returned without calling the LLM | Saves cost and avoids answers made up from general knowledge. |
| **History** | Only conversation text is sent back, never old sources; it's normalised to alternating turns | Each turn retrieves fresh sources, and the request is always valid for the Messages API. |
| **Ingestion** | Queued, unique, idempotent job (chunks replaced in one transaction); bad files fail permanently instead of retrying | Uploads return instantly, and retries never leave duplicate chunks behind. |
| **Dedup** | SHA-256 of the upload, per user | Uploading the same file twice returns the existing document. |
| **Accounts** | Session-cookie auth with CSRF protection (the SPA is same-origin, so no API tokens); every document has an owner, and retrieval is always scoped to the signed-in user's documents. Email verification (enforced with `DOCQA_VERIFY_EMAIL=true`), password reset by emailed link, and account deletion that removes every document, chunk and file | One user can never retrieve or cite another user's text. Other users' document ids answer 404, so they don't reveal that a document exists. The reset form gives the same reply whether or not an account exists, and deleting an account needs the current password. |
| **Usage limits** | Per-user rate limits on uploads and questions, plus a daily LLM token budget per user and for the whole app | An open deployment can't run up an unbounded bill. Each question reserves an upper estimate of its cost (the longest prompt it can produce, the longest answer allowed, and the query rewrite) with an atomic increment, so questions sent at the same moment can't overshoot together. The reservation is replaced by the usage the model reports, including the rewrite call. |
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

Limits are set in `.env` (0 means unlimited): `RAG_UPLOADS_PER_HOUR` (30), `RAG_QUESTIONS_PER_MINUTE` (30), `RAG_DAILY_TOKENS_PER_USER` (300,000) and `RAG_DAILY_TOKENS_TOTAL` (3,000,000). Set `DOCQA_REGISTRATION=false` to stop new sign-ups, and `DOCQA_VERIFY_EMAIL=true` to require a verified email address before uploading or asking. Verification and password-reset emails go through Laravel's mailer; the default `MAIL_MAILER=log` writes them to `storage/logs/laravel.log`, so set real SMTP details for a public deployment.

---

## API

Every endpoint except `register`, `login`, `forgot-password` and `reset-password` needs a signed-in session. With `DOCQA_VERIFY_EMAIL=true`, the document and chat endpoints also need a verified email address (403 otherwise). State-changing requests send the `X-XSRF-TOKEN` header from Laravel's `XSRF-TOKEN` cookie.

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/v1/register` | Create an account and sign in (`name`, `email`, `password`, `password_confirmation`) |
| `POST` | `/api/v1/login` | Sign in (`email`, `password`) |
| `POST` | `/api/v1/logout` | Sign out |
| `POST` | `/api/v1/forgot-password` | Email a password-reset link (`email`) |
| `POST` | `/api/v1/reset-password` | Set a new password (`token`, `email`, `password`, `password_confirmation`) |
| `GET` | `/api/v1/user` | The signed-in user, with verification status and today's token usage and limit |
| `DELETE` | `/api/v1/user` | Delete the account and all its documents (`password`) |
| `POST` | `/api/v1/email/verification-notification` | Send the verification email again |
| `GET` | `/api/v1/documents` | List your documents with status (`pending` → `processing` → `ready` / `failed`) |
| `POST` | `/api/v1/documents` | Upload (`multipart/form-data`, field `file`, optional `title`) |
| `GET` | `/api/v1/documents/{id}` | Show one document |
| `POST` | `/api/v1/documents/{id}/reprocess` | Re-run ingestion |
| `DELETE` | `/api/v1/documents/{id}` | Delete the document, its file and its chunks |
| `POST` | `/api/v1/chat` | Ask a question; responds with `text/event-stream`, or 429 when the question's worst-case cost no longer fits in the daily token budget |

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
php artisan rag:eval-answers       # answer quality with the real LLM and an separate LLM judge (costs tokens)
php artisan test                   # 88 tests, no network calls
```

## Retrieval evaluation

`php artisan rag:eval` measures how often retrieval finds the right passage. The golden set in [`resources/eval`](resources/eval) has seven fictional company documents (handbook, product manuals, policies, a runbook) and 56 questions. Each question names the document and a phrase the retrieved chunk must contain, so the set doesn't depend on chunk size. The documents deliberately overlap: both product manuals have an error `E-12` that means different things, and two policies have 24-hour reporting rules.

The command indexes the corpus with the real ingestion pipeline inside a database transaction that is always rolled back, then runs every question through vector-only, keyword-only and both hybrid fusions: Reciprocal Rank Fusion (RRF) and weighted score fusion, the default. CI runs it on both databases with the offline `hash` embeddings. Results (k=6, 17 chunks):

| Mode | `hash` · SQLite | `hash` · pgvector | `nomic-embed-text` (Ollama) · SQLite |
|---|---|---|---|
| Vector only | 61% / 0.717 | 61% / 0.717 | 66% / 0.785 |
| Keyword only | 68% / 0.750 | 63% / 0.722 | 68% / 0.750 |
| Hybrid, RRF | 66% / 0.760 | 68% / **0.766** | 64% / 0.775 |
| Hybrid, weighted (default) | **70% / 0.774** | 68% / 0.763 | **71% / 0.838** |

Cells are Hit@1 / MRR. The Ollama column was run locally (`ollama pull nomic-embed-text`, then `EMBEDDING_PROVIDER=ollama EMBEDDING_DIMENSIONS=768 php artisan rag:eval` on a fresh database). With `nomic-embed-text`, weighted fusion finds the expected passage in the top 6 for all 56 questions (RRF: 53). Hit@1 by question type:

| Question type | `hash` vector | `hash` RRF | `hash` weighted | `nomic` vector | `nomic` RRF | `nomic` weighted |
|---|---|---|---|---|---|---|
| Exact identifiers (17) | 76% | 88% | **94%** | 65% | 76% | **88%** |
| Shared keywords (18) | 94% | 94% | 94% | 83% | 83% | **94%** |
| Paraphrases (21) | 19% | 24% | 29% | **52%** | 38% | 38% |

What this shows:

- **A semantic model fixes paraphrases.** "Will the company pay my speeding ticket?" shares no words with "Fines for traffic or parking violations are never reimbursed", but `nomic-embed-text` ranks that passage 1st. Vector-only Hit@1 on paraphrases rises from 19% with `hash` to 52%, and Hit@3 overall from 80% to 91%.
- **Keyword search still wins on exact terms.** `nomic` vector search finds the passage first for only 65% of identifier questions, against 88% for keyword search. That's the case for hybrid retrieval.
- **RRF lost the vector ranking's best hits.** RRF adds 1 / (60 + rank) for each list a chunk is in, so a chunk that's mediocre in both lists beats one that's 1st in one list and absent from the other. With `nomic`, the speeding-ticket passage was 1st for vector search, had no keyword match, and fell to 9th. Part of this is the small corpus: the 20 candidates cover all 17 chunks, so almost every chunk gets a second RRF score.
- **Weighted score fusion keeps them.** Each list's scores are scaled to 0–1 (best = 1) and combined as 0.6 × vector + 0.4 × keyword, so a clear winner on one side keeps most of its lead. With `nomic` it lifts MRR from 0.775 to 0.838 and fixes all three RRF misses. With `hash` the two fusions are close (better on SQLite, a tie on Postgres), because `hash` vectors are themselves lexical. The weight was picked from 0.5–0.9 on this golden set, so it's tuned to it; a larger set would confirm it.
- **The evaluation caught a Postgres tokenisation bug.** The default text-search parser reads `E-417` as the word `e` plus the negative number `-417`, so a search for `417` never matched it. Keyword-only Hit@1 on exact questions was 65% (vs 88% for the PHP scorer). Indexing content with hyphens replaced by spaces raised it to 82%, and hybrid Hit@1 on Postgres from 64% to 68%.

The corpus also doubles as sample documents to upload and try the app with.

## Answer evaluation

Retrieval metrics don't say whether the final answers are right. `php artisan rag:eval-answers` runs every golden-set question through the whole pipeline (retrieval and Claude) and checks each answer two ways:

- **Deterministic checks:** do the `[n]` citations point at sources that exist, and does a cited source contain the expected passage?
- **An LLM judge**, given the question, the retrieved sources, the answer and the expected phrase: is the answer **correct**, and is every claim **faithful** to the sources?

The judge runs on a different model from the one that writes the answers (`RAG_JUDGE_MODEL`, default `claude-sonnet-5-5`, or `--judge=` per run), so it isn't grading its own style.

The golden set also has 8 questions the documents don't answer ("Who is the CEO?"). For those, the right answer is to say so, which measures how often the system makes things up. The run makes two LLM calls per question, so it isn't in CI. Results with `nomic-embed-text`, answers from `claude-opus-5-5` (64 questions, about 150k input tokens):

| Metric | First run (RRF, Opus judging itself) | Now (sections kept whole, weighted fusion, Sonnet judge) |
|---|---|---|
| Correct (56 answerable) | 95% (53) | **100% (56)** |
| Faithful to the sources (all 64) | 98% (1 unfaithful) | 95% (3 unfaithful) |
| Cites the expected passage | 95% | **100%** |
| Expected passage retrieved | 95% | **100%** |
| Answers citing a source that doesn't exist | 0% | 0% |
| Says "not in the documents" when it isn't (8 unanswerable) | 100% | 100% |

What this shows:

- **Retrieval was the bottleneck, not generation.** In the first run every wrong answer was a question whose passage wasn't retrieved, and in all three Claude said it couldn't find the answer instead of guessing. The run also found a chunk that ended at the heading "Using your own car", with the rules in the next chunk. After the chunking and fusion fixes the expected passage is retrieved for all 56 questions, and all 56 answers are correct.
- **No made-up answers.** All 8 unanswerable questions got "the documents don't say", and no answer cited a source that doesn't exist.
- **A separate judge is stricter.** Sonnet flagged three answers where Opus, judging its own answers, had flagged one. I checked all three by hand and agree. Each gets the main fact right, then adds an unsupported side remark about the other product or a nearby section, such as "the mower has no Eco mode". The pattern is over-answering, which a "stick to what was asked" rule in the system prompt could address.

Spot-check the judge with `--failures`, which lists every answer marked incorrect or unfaithful with the judge's reason.

## Configuration reference

All tunables are in [`config/rag.php`](config/rag.php): chunk size and overlap, `top_k`, candidate pool size, hybrid on/off, the fusion method and vector weight, the RRF constant, minimum similarity, query rewriting, history length, model and effort, and the judge model for `rag:eval-answers`.

## Limitations / next steps

- **Scanned PDFs** have no text layer. They fail with a clear message; adding OCR (e.g. Tesseract) at the extraction step would fix that.
- **No roles or sharing**: every account sees only its own documents; there are no admins, teams or shared collections. Documents uploaded before accounts existed have no owner, and nobody sees them until they're assigned a `user_id`.
- **Token budget reservations are conservative**: the worst case is mostly the answer limit (`ANTHROPIC_MAX_TOKENS`, 16,000), so the last ~20k tokens of a day's budget can't be used, and a client that disconnects mid-answer keeps its whole reservation. Prompt sizes are estimated at ~4 characters per token, so text that tokenises more densely can make the estimate low.
- **Re-ranking**: a cross-encoder re-ranker (e.g. Voyage `rerank-2.5`) over the fused top-20 would sharpen precision further.
- **Bigger golden set**: 56 questions over 17 chunks is enough to compare modes, but too small to tune `top_k` or chunk size, and the fusion weight was picked on this same set. A larger corpus would make Hit@k meaningful.
- **Filtered HNSW**: when scoping to a few documents on a large corpus, enable pgvector ≥ 0.8 iterative scans (`SET hnsw.iterative_scan = relaxed_order`).

## License

[MIT](LICENSE)
