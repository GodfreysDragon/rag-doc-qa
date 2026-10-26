# DocQA: RAG document Q&A

[![tests](https://github.com/GodfreysDragon/rag-doc-qa/actions/workflows/tests.yml/badge.svg)](https://github.com/GodfreysDragon/rag-doc-qa/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Upload PDFs, Word documents, Markdown or text files and ask questions about them. Answers stream back with inline citations that link to the exact passages they came from.

![DocQA: sign in, upload a document, ask a question, get a streamed answer with citations that open the source passage](.github/demo.gif)

The retrieval pipeline is built by hand rather than with a hosted "chat with your PDF" API: text extraction, chunking, embeddings, vector + keyword search, score fusion, re-ranking and prompt grounding are all in this repo.

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
           ─► keyword search (ts_rank, top 20)┴► weighted score fusion ─► top 20
           ─► re-rank (Claude Haiku reads the question and all 20) ─► top 6
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
| **Re-ranking** | A small Claude model (`claude-haiku-5-5`) gets the question and the 20 best fused passages in one call and returns their ids in order of relevance; the top 6 go to the prompt. `RAG_RERANKER=voyage` uses Voyage's `rerank-2.5` cross-encoder instead, and `none` turns it off | First-stage retrieval scores the question and each passage separately (one vector each, or word overlap), so it can't tell "states the answer" from "uses the same words". A model that reads them together can. On the golden sets it lifts Hit@1 to 98–100% (see the evaluation). If the call fails, the fused order is used. |
| **Query rewriting** | Only when there's history | "What about the second one?" embeds badly on its own, so it's rewritten into a standalone query first. The first turn skips that LLM call. |
| **Grounding** | Sources in tagged blocks with ids; the system prompt requires `[n]` citations, a plain "not in the documents" when the sources don't cover it, answers that stick to what was asked with no claim a source doesn't state, and treats source text as data | Cuts down on hallucination and prompt injection from uploaded files, and makes every claim checkable in the UI. |
| **Citation highlighting** | Clicking `[n]` matches the claim before it against the sentences of source `n` (shared terms, numbers weighted double) and highlights the best match | A citation points at a whole ~300-token chunk; this narrows it to the sentence a reader actually needs to check. Done client-side, so it costs no extra LLM call. |
| **No-context short-circuit** | If nothing is retrieved, a fixed reply is returned without calling the LLM | Saves cost and avoids answers made up from general knowledge. |
| **History** | Only conversation text is sent back, never old sources; it's normalised to alternating turns | Each turn retrieves fresh sources, and the request is always valid for the Messages API. |
| **Ingestion** | Queued, unique, idempotent job (chunks replaced in one transaction); bad files fail permanently instead of retrying | Uploads return instantly, and retries never leave duplicate chunks behind. |
| **Dedup** | SHA-256 of the upload, per user | Uploading the same file twice returns the existing document. |
| **Accounts** | Session-cookie auth with CSRF protection (the SPA is same-origin, so no API tokens); every document has an owner, and retrieval is always scoped to the signed-in user's documents. Email verification (enforced with `DOCQA_VERIFY_EMAIL=true`), password reset by emailed link, and account deletion that removes every document, chunk and file | One user can never retrieve or cite another user's text. Other users' document ids answer 404, so they don't reveal that a document exists. The reset form gives the same reply whether or not an account exists, and deleting an account needs the current password. |
| **Usage limits** | Per-user rate limits on uploads and questions, plus a daily LLM token budget per user and for the whole app | An open deployment can't run up an unbounded bill. Each question reserves an upper estimate of its cost (the longest prompt it can produce, the longest answer allowed, the query rewrite and the re-ranking call) with an atomic increment, so questions sent at the same moment can't overshoot together. The reservation is replaced by the usage the model reports, including the rewrite and re-ranking calls. |
| **LLM safety** | `fallbacks: "default"` server-side fallback, handling of `refusal` stop reasons, and a `reset` event if a fallback takes over mid-stream | A classifier decline is retried on another model rather than shown to the user as an empty answer. |

### Swappable components

Everything sits behind small interfaces in [`app/Services/Rag`](app/Services/Rag) and is wired up in [`AppServiceProvider`](app/Providers/AppServiceProvider.php):

- `EmbeddingProvider`: `VoyageEmbeddingProvider`, `OllamaEmbeddingProvider`, and `HashEmbeddingProvider`, an offline feature-hashing baseline. It's lexical only, so it makes a good control to compare real embeddings against, and it lets the app and tests run without keys.
- `VectorStore`: `PgvectorStore`, which does everything in SQL, and `DatabaseVectorStore`, which stores JSON embeddings and does an exact brute-force cosine scan in PHP on MySQL/SQLite.
- `ChatModel`: `ClaudeChatModel`, plus a `FakeChatModel` used in tests.
- `Reranker`: `ClaudeReranker` (listwise, through `ChatModel`) and `VoyageReranker`.

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
php artisan rag:eval               # retrieval quality on the tuning set (see below)
php artisan rag:eval --set=resources/eval/holdout.json   # …and on the held-out set
php artisan rag:eval --misses      # …and list the questions hybrid search missed
php artisan rag:eval --rerank=claude   # …and add re-ranking to the comparison (one Haiku call per question)
php artisan rag:eval-answers       # answer quality with the real LLM and an separate LLM judge (costs tokens)
php artisan test                   # 101 tests, no network calls
```

## Retrieval evaluation

`php artisan rag:eval` measures how often retrieval finds the right passage. The corpus in [`resources/eval/corpus`](resources/eval/corpus) has twelve fictional company documents (27 chunks): a handbook, contractor guidelines, an onboarding guide, two product manuals and an accessory manual, and policies for travel, procurement, security and data retention, plus a runbook and support SLAs. They overlap on purpose: the auto-empty dock manual shares most of its vocabulary with the vacuum manual, both robot manuals have an error `E-12` that means different things, contractors and employees have different rules for leave and expenses, and procurement and expenses both talk about approvals, receipts and Finance.

There are two golden sets over that corpus. Each question names the document and a phrase the retrieved chunk must contain, so the sets don't depend on chunk size, and a test checks every phrase really is in its document.

- **Tuning set** ([`questions.json`](resources/eval/questions.json), 56 questions): the set the fusion method and weight were chosen on.
- **Held-out set** ([`holdout.json`](resources/eval/holdout.json), 54 questions, `--set=resources/eval/holdout.json`): written afterwards together with five of the documents, and never used for tuning. It shows whether the settings generalise.

The command indexes the corpus with the real ingestion pipeline inside a database transaction that is always rolled back, then runs every question through vector-only, keyword-only and both hybrid fusions: Reciprocal Rank Fusion (RRF) and weighted score fusion, the default. CI runs both sets on both databases with the offline `hash` embeddings. Results (k=6, Hit@1 / MRR):

| Mode | Tuning · `hash` · SQLite | Tuning · `hash` · pgvector | Tuning · `nomic` · SQLite | Held-out · `hash` · SQLite | Held-out · `hash` · pgvector | Held-out · `nomic` · SQLite |
|---|---|---|---|---|---|---|
| Vector only | 57% / 0.667 | 57% / 0.667 | 61% / 0.747 | 57% / 0.679 | 57% / 0.679 | 67% / 0.785 |
| Keyword only | 66% / 0.726 | 55% / 0.658 | 66% / 0.726 | 70% / 0.807 | 65% / 0.779 | 70% / 0.807 |
| Hybrid, RRF | 64% / 0.734 | **63% / 0.721** | 57% / 0.728 | **70% / 0.817** | **67% / 0.794** | 76% / 0.866 |
| Hybrid, weighted (default) | **66% / 0.736** | 61% / 0.701 | **64% / 0.786** | 67% / 0.777 | 59% / 0.742 | **81% / 0.883** |

`nomic` is `nomic-embed-text` via Ollama, run locally (`ollama pull nomic-embed-text`, then `EMBEDDING_PROVIDER=ollama EMBEDDING_DIMENSIONS=768 php artisan rag:eval` on a fresh database). With it, weighted fusion has the expected passage in the top 6 for 54 of 56 tuning questions and all 54 held-out ones. Hit@1 by question type with `nomic`:

| Question type | Tuning: vector | RRF | weighted | Held-out: vector | RRF | weighted |
|---|---|---|---|---|---|---|
| Exact identifiers (17 / 11) | 65% | 71% | **76%** | 73% | 91% | **100%** |
| Shared keywords (18 / 20) | 78% | 83% | **94%** | 80% | 90% | **95%** |
| Paraphrases (21 / 23) | **43%** | 24% | 29% | 52% | 57% | **61%** |

What this shows:

- **The weighted default generalises with a semantic model.** On the held-out set it's the best mode with `nomic` (MRR 0.883 vs 0.866 for RRF). A sweep on the held-out questions puts the best weight at 0.7 (MRR 0.892), within 0.01 of the 0.6 default, so the weight was not overfitted to the tuning set. I kept 0.6 rather than re-tune on the held-out set, which would stop it being held out.
- **With the lexical `hash` baseline, RRF is as good or better.** `hash` vectors are themselves word overlap, so both lists agree and rewarding agreement (RRF) works; on Postgres RRF wins on both sets. The default is chosen for the real embedding models the app is meant to run with, and `RAG_FUSION=rrf` is there for lexical setups.
- **The overlapping documents make it harder.** Adding them (17 → 27 chunks, now more than the 20 candidates per search) lowered tuning-set MRR with `nomic` from 0.838 to 0.786: "Is there money for setting up a desk at my house?" now competes with the contractor and onboarding guides.
- **A semantic model fixes paraphrases, keyword search fixes identifiers.** "Will the company pay my speeding ticket?" shares no words with "Fines for traffic or parking violations are never reimbursed", but `nomic` finds it; vector-only paraphrase Hit@1 is 43% with `nomic` against 14% with `hash`. Keyword search still wins on exact codes (88% vs 65% for `nomic` vector search), which is the case for hybrid retrieval.
- **Why RRF stopped being the default.** RRF adds 1 / (60 + rank) for each list a chunk is in, so a chunk that's mediocre in both lists beats one that's 1st in one list and absent from the other. On the original seven documents, `nomic` ranked the speeding-ticket passage 1st, it had no keyword match, and RRF dropped it to 9th. Weighted fusion scales each list to 0–1 and adds 0.6 × vector + 0.4 × keyword, so a clear winner on one side keeps its lead.
- **The evaluation caught a Postgres tokenisation bug.** The default text-search parser reads `E-417` as the word `e` plus the negative number `-417`, so a search for `417` never matched it. Indexing content with hyphens replaced by spaces fixed exact-code searches on Postgres.

### Re-ranking

`php artisan rag:eval --rerank=claude` adds a fifth mode: weighted fusion, then the Claude re-ranker over the top 20. With `nomic-embed-text` (Hit@1 / MRR, k=6):

| Mode | Tuning set (56) | Held-out set (54) |
|---|---|---|
| Hybrid, weighted | 64% / 0.786 | 81% / 0.883 |
| Hybrid, weighted, then re-ranked (default) | **100% / 1.000** | **98% / 0.991** |

| Hit@1 by question type | Tuning: weighted | re-ranked | Held-out: weighted | re-ranked |
|---|---|---|---|---|
| Exact identifiers | 76% | **100%** | 100% | 100% |
| Shared keywords | 94% | **100%** | 95% | **100%** |
| Paraphrases | 29% | **100%** | 61% | **96%** |

- **Paraphrases were the weak spot, and re-ranking fixes them.** First-stage retrieval usually had the right passage somewhere in the top 20 but not first; a model reading the question next to each passage picks it out. The two tuning questions weighted fusion missed from the top 6 ("money for setting up a desk at my house", "mop up spilled water") both went to 1st.
- **The held-out set confirms it.** 53 of 54 at rank 1. The one change for the worse moved "Who can I ask the silly questions when I start?" from 1st to 2nd.
- **The cost:** about 6,500 Haiku input and 70 output tokens per question, and roughly 1.5 seconds before the answer starts streaming. Those tokens count against the usage limits like the rest of the question.
- **Caveat:** the re-ranker is a general model, not tuned on these sets, but 110 questions over 27 chunks is still a small corpus; with thousands of chunks the gain would depend more on first-stage recall in the top 20.

The corpus also doubles as sample documents to upload and try the app with.

## Answer evaluation

Retrieval metrics don't say whether the final answers are right. `php artisan rag:eval-answers` runs every golden-set question through the whole pipeline (retrieval and Claude) and checks each answer two ways:

- **Deterministic checks:** do the `[n]` citations point at sources that exist, and does a cited source contain the expected passage?
- **An LLM judge**, given the question, the retrieved sources, the answer and the expected phrase: is the answer **correct**, and is every claim **faithful** to the sources?

The judge runs on a different model from the one that writes the answers (`RAG_JUDGE_MODEL`, default `claude-sonnet-5-5`, or `--judge=` per run), so it isn't grading its own style.

Each golden set also has questions the documents don't answer: 8 in the tuning set ("Who is the CEO?") and 6 in the held-out set. For those, the right answer is to say so, which measures how often the system makes things up. The run makes two LLM calls per question plus the re-ranking, so it isn't in CI; one set costs about 550k input tokens, two-thirds of them Haiku re-ranking. Results with `nomic-embed-text`, answers from `claude-opus-5-5`:

| Metric | Tuning: first run (7 documents, RRF, Opus judging itself) | Tuning: re-ranked, earlier prompt | Tuning: re-ranked, "answer only what was asked" (default) | Held-out: earlier prompt | Held-out: default |
|---|---|---|---|---|---|
| Correct (56 / 54 answerable) | 95% (53) | 100% | 100% | 100% | 100% |
| Faithful to the sources (64 / 60) | 98% (1 unfaithful) | 95% (3 unfaithful) | **98% (1)** | 100% | 100% |
| Cites the expected passage | 95% | 100% | 100% | 100% | 100% |
| Expected passage retrieved | 95% | 100% | 100% | 100% | 100% |
| Answers citing a source that doesn't exist | 0% | 0% | 0% | 0% | 0% |
| Says "not in the documents" when it isn't (8 / 6) | 100% | 100% | 100% | 100% | 100% |
| Average answer length | | 75 words | **50 words** | 76 words | **50 words** |

What this shows:

- **Retrieval was the bottleneck, not generation.** In the first run every wrong answer was a question whose passage wasn't retrieved, and in all three Claude said it couldn't find the answer instead of guessing. The run also found a chunk that ended at the heading "Using your own car", with the rules in the next chunk. After the chunking, fusion and re-ranking changes, the expected passage is retrieved for every question in both sets and every answer is correct.
- **The remaining failure mode was over-answering, and a prompt rule fixed most of it.** The Sonnet judge kept flagging the same pattern: the main fact right, then an unsupported side remark ("the M1 mower is not designed for this either", the People team handling "benefits-related topics"). The system prompt now says to answer only what was asked, to leave out other products and neighbouring sections, and never to conclude that something is missing or not allowed just because a source doesn't mention it. Unfaithful answers on the tuning set went from 3 to 1, answers got a third shorter, and the held-out set stayed at 100% correct and faithful, so the shorter answers didn't lose anything. The one left adds a small inference ("the map needs to exist before you can draw zones").
- **No made-up answers.** Every unanswerable question got "the documents don't say", and no answer cited a source that doesn't exist.
- **A separate judge is stricter.** On the first run Sonnet flagged three answers where Opus, judging its own answers, had flagged one. I check every flagged verdict by hand, and so far I've agreed with each of them.

Spot-check the judge with `--failures`, which lists every answer marked incorrect or unfaithful with the judge's reason.

## Configuration reference

All tunables are in [`config/rag.php`](config/rag.php): chunk size and overlap, `top_k`, candidate pool size, hybrid on/off, the fusion method and vector weight, the RRF constant, the re-ranker and its candidate count, minimum similarity, query rewriting, history length, model and effort, and the judge model for `rag:eval-answers`.

## Limitations / next steps

- **Scanned PDFs** have no text layer. They fail with a clear message; adding OCR (e.g. Tesseract) at the extraction step would fix that.
- **No roles or sharing**: every account sees only its own documents; there are no admins, teams or shared collections. Documents uploaded before accounts existed have no owner, and nobody sees them until they're assigned a `user_id`.
- **Token budget reservations are conservative**: the worst case is mostly the answer limit (`ANTHROPIC_MAX_TOKENS`, 16,000), so the last ~20k tokens of a day's budget can't be used, and a client that disconnects mid-answer keeps its whole reservation. Prompt sizes are estimated at ~4 characters per token, so text that tokenises more densely can make the estimate low.
- **Re-ranking adds latency and cost**: about 1.5 seconds and ~6.5k Haiku tokens per question. The Voyage cross-encoder option is faster, but it's only covered by tests with a faked API here, not measured.
- **Golden set size**: 110 questions over 27 chunks separate the retrieval modes and check the fusion weight on held-out questions, but they're still too few to tune `top_k` or chunk size with confidence.
- **Filtered HNSW needs pgvector ≥ 0.8**: every search is scoped to the user's documents, and an HNSW scan filters its first 40 candidates after the fact, so a user with a few documents in a large table could get no results. The connection turns on iterative scans (`hnsw.iterative_scan = strict_order`, `DB_HNSW_ITERATIVE_SCAN`), which keep walking the index until enough rows match; the bundled `pgvector/pgvector:pg17` image supports them. Older pgvector versions don't have the setting, so they keep the problem.

## License

[MIT](LICENSE)
