# Project Summary: DocQA (RAG Document Q&A)

_Last updated: 2026-10-04_

## What it is

DocQA is a web app for asking questions about your own documents. You upload PDF, DOCX, TXT or Markdown files. Laravel extracts the text, chunks it and embeds it in a background job, and the vectors are stored in pgvector (Postgres) or as JSON on MySQL/SQLite. The Vue chat UI then streams answers from Claude with clickable `[n]` citations that open the exact source passage.

The retrieval pipeline is written by hand rather than wrapping a hosted "chat with your PDF" API. Extraction, chunking, embeddings, vector and keyword search, rank fusion and prompt grounding all live in `app/Services/Rag`.

**Stack:** Laravel 13 · PHP 8.4 · Vue 3 · Tailwind 4 · PostgreSQL + pgvector (MySQL/SQLite fallback) · Claude Opus 5.5 via the Anthropic PHP SDK · Voyage AI or Ollama embeddings

## What was built

### Ingestion (index time)
- **Text extraction** from PDF (page by page), DOCX (paragraphs and tables) and TXT/MD (handles a UTF-8 BOM and Windows-1252 text). Words hyphenated across line breaks are re-joined.
- **Recursive chunking**: splits on paragraph, then line, then sentence, then word, aiming for about 300 tokens per chunk with about 50 tokens of overlap. Chunks never cross a page, so every citation has a page number.
- **Pluggable embeddings**: Voyage AI (hosted), Ollama (local), or an offline `hash` baseline that needs no key. Documents and queries are embedded separately because retrieval models encode them differently, and all vectors are normalised. The document title is embedded along with each chunk to make chunks easier to find.
- **Queued, idempotent job**: a document's chunks are replaced in one transaction, unreadable files fail permanently instead of retrying, and duplicate uploads are detected by SHA-256 hash.
- **`rag:reindex` command** (with `--stale` and `--queue` options) for re-embedding after changing the model or chunking settings.

### Retrieval and answering (query time)
- **Hybrid search**: vector search (pgvector HNSW index, or an exact cosine scan in PHP) plus keyword search (Postgres full-text, or a TF-IDF-style score in PHP), merged with Reciprocal Rank Fusion.
- **Query rewriting**: follow-up questions are turned into standalone search queries, but only when there's conversation history.
- **Grounded prompt**: sources are passed as numbered `<source>` blocks and Claude must cite them. Text inside sources is treated as data, not instructions. If nothing relevant is found, a fixed reply is returned without calling the LLM.
- **Streaming over SSE**: the events are `query`, `sources`, `text`, `done`, `error`, plus `reset` if a fallback model takes over mid-stream.
- **Claude integration**: effort is set explicitly, server-side fallback (`fallbacks: "default"`) is on, refusals are handled, and API errors are turned into clear messages.

### Frontend
- Drag-and-drop uploads with progress bars, a document library with live status, and checkboxes to limit a search to selected documents.
- A streaming chat that renders Markdown and shows citation chips, the search query actually used, and source cards with similarity and keyword scores.
- A responsive layout (the sidebar becomes a drawer on mobile) with automatic light and dark mode.

### Quality
- **37 PHPUnit tests** covering chunking, extraction (including PDFs and DOCX files generated inside the tests), upload, dedup, failure handling, reindexing, retrieval and rank fusion, prompt construction, history normalisation, query rewriting, and the SSE stream and its error events. A fake LLM is used, so the tests make no network calls.
- Code formatted with Pint; follows the Laravel Boost conventions (form requests, API resources, factories, enum casts).

## Verification status

| Area | Status |
|---|---|
| Test suite | ✅ 37/37 passing |
| Upload → queue → chunk → embed over real HTTP (SQLite + `hash` embeddings) | ✅ Verified |
| Hybrid retrieval returning ranked sources over SSE | ✅ Verified |
| UI at desktop and mobile widths | ✅ Checked with headless Edge screenshots |
| Live Claude answers | ⚠️ Not verified: no `ANTHROPIC_API_KEY` on this machine. The request reaches Anthropic and gets 401, which the UI reports clearly |
| pgvector store (HNSW + tsvector) | ⚠️ Not verified: Postgres and Docker aren't installed. `docker-compose.yml` is provided |
| Voyage / Ollama embeddings | ⚠️ Not verified: no key or local Ollama install |

## Notable fixes made along the way
- **Windows uploads:** `php artisan serve` strips `TEMP`/`TMP` from the environment, so every upload failed with "unable to create a temporary file". Both variables are now passed through in `AppServiceProvider::boot()`.
- **Queue timing:** `DB_QUEUE_RETRY_AFTER=360` is set above the job's 300-second timeout, so a slow ingest isn't picked up by two workers.
- **DOCX validation:** uploads are checked against an explicit allow-list of MIME types, because some systems report DOCX as `application/zip`.

## Next steps

### To run it with real models
1. Add `ANTHROPIC_API_KEY` to `.env`.
2. Optional: set `EMBEDDING_PROVIDER=voyage` and add `VOYAGE_API_KEY` (or install Ollama and run `ollama pull nomic-embed-text`, with `EMBEDDING_DIMENSIONS=768`).
3. Optional: run `docker compose up -d`, switch `.env` to the pgsql block, then run `php artisan migrate:fresh`.
4. Run `php artisan rag:reindex` if documents already exist, then `composer run dev`.
5. Put the repository under version control (`git init` and a first commit). This hasn't been done yet.

### Possible improvements
- **OCR for scanned PDFs**: they currently fail with a clear message; Tesseract could be added at the extraction step.
- **Re-ranking**: run a cross-encoder (e.g. Voyage `rerank-2.5`) over the fused top 20 for better precision.
- **Retrieval evaluation**: build a small golden set of question → expected-chunk pairs and measure recall@k as chunking and retrieval settings change.
- **Auth and multi-tenancy**: add `user_id` to documents and filter every query by it. It's currently a single-user demo.
- **Filtered HNSW on large corpora**: with pgvector ≥ 0.8, enable `hnsw.iterative_scan` so searches limited to a few documents still return enough results.
- **Persisted conversations**: chat history currently lives only in the browser.
