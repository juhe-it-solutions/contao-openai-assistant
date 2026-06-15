# Automatic Vector-Store Updates — Redesign Plan

> Status: **implemented** (phases 0-5 complete; audited; pre-merge reviewed 2026-06-15)
> Owner: JUHE IT-solutions
> Last updated: 2026-06-15

Implementation reference for the redesign of the automatic vector-store sync. Each phase
below has concrete tasks with checkboxes a coding agent can work through and tick off.

## Pre-merge review & hardening (2026-06-15)

Independent pre-merge review of branch `feature/auto-vectorstore-updates` against `main`.
All quality gates now pass: `php -l`, PHPStan level 5 (0 errors), ECS (clean), and the
`BoilerplateFilter` unit test (8 assertions). **Changes are code/tooling only — no behaviour
regressions.**

### CI blockers found and fixed
The CI workflow (`.github/workflows/ci.yml`) gates on `ecs check`, `ecs check --fix` +
git-clean, and PHPStan level 5. As committed, the branch would have **failed CI**:

1. **PHPStan error** — `OpenAiConfigListener::removePasswordChangedMessage()` called
   `getFlashBag()` on `SessionInterface`, which does not declare it. Fixed with a
   `FlashBagAwareSessionInterface` instance guard.
2. **ECS crash** — the bundled `Contao\…\CommentLengthFixer` aborts ("Cannot set empty
   content for id-based Token") on the new files' PHPDoc array-shape annotations
   (`@param array{…}`, `@return list<array{…}>`). **Correction to the earlier audit note:
   this is _not_ a pre-existing environment issue** — baseline (`origin/main`) files do not
   crash; only the branch's new comment style does. Resolved by skipping that single buggy
   fixer in `ecs.php` (the type annotations are kept because they aid PHPStan; re-enable once
   the upstream fixer is fixed). The new files were then formatted to the Contao standard via
   `ecs check --fix` (whitespace, element/import ordering, array indentation — all cosmetic).

### Concurrency hardening — run lease via heartbeat (supersedes the 30-min guard)
The previous design protected against concurrent syncs only with a status + 30-minute
stale-window heuristic. That left two gaps: (a) a sync running longer than 30 min (a large
first sync) stopped being protected and could be raced by a sub-daily schedule tick or a
manual "Run now" click; (b) the brief `queued` window before a dispatched run flips to
`running` was not treated as in-flight by the cron. Two overlapping runs would upload
**duplicate** vector-store files (doubled cost, worse retrieval).

Fixed with a proper **run lease**:
- A live run now **refreshes `auto_update_last_run` periodically** (`heartbeat()` in
  `VectorStoreAutoUpdateService`, throttled to `HEARTBEAT_INTERVAL = 60 s`, scoped to
  `status = 'running'`). It is called once per page from `VectorStoreFileSync::sync()` (via a
  callback) and during the crawl — `spawnCrawl()` now **polls the process** instead of a
  blocking `wait()` so the lease stays fresh through a long crawl (process timeout disabled).
- The stale window (`STALE_RUN_SECONDS`) is now **public and shared** by the cron and the
  manual dispatch guard, and **tightened to 900 s** (it only has to cover one heartbeat gap +
  the slowest single page, not the whole sync).
- `VectorStoreAutoUpdateCron::isDue()` now treats **both `running` and `queued`** as in-flight
  while the lease is fresh.

Net effect: a long but healthy sync keeps its lease and is never raced; a genuinely crashed
run lets the lease go stale and is taken over on the next tick. This **resolves the
"concurrent second run uploads duplicates" limitation** noted below; the defensive unique
`(pid, page_id)` key (option #3) was deemed unnecessary and not added.

### Other observations (not blocking; not changed)
- **phpunit is not installed and CI has no test step** — `BoilerplateFilterTest` only runs by
  hand (verified passing). Wiring it into CI was explicitly declined for now.
- **Plan page-limit is enforced at config-save only**, not at sync runtime; a site that grows
  past its plan after saving still indexes up to `MAX_CRAWL_PAGES = 5000`. Low severity.
- **Crawl indexes the public site** (`contao:crawl`); genuinely auth-gated intranet pages may
  never enter `tl_search`. Customer-facing caveat.
- **`composer.lock` is not committed** (bundle convention) → CI resolves php-cs-fixer fresh;
  watch the first CI run in case its fixer version formats differently from local.

## 0. Implementation status & key decisions

All five phases are implemented and audited. Highlights / deviations from the original draft:

- **Table creation: no migration.** The state table `tl_openai_vector_file` is created by
  Contao's Doctrine schema sync from its DCA `sql` definitions (the same mechanism as
  `tl_openai_sync_log`). Contao dropped `database.sql` in favour of DCA definitions
  (UPGRADE.md), and the schema provider scans every bundle's `contao/dca/*.php`, so no
  migration is needed.
- **Attach method: single-file create, not batches.** Each page file is attached with
  `POST /v1/vector_stores/{id}/files` including per-file `attributes` (page_id, url, title,
  language, content_hash, chunk). If the API rejects `attributes` (older accounts), the call
  transparently retries without them. (File batches remain a future throughput optimisation.)
- **LLM polish is optional and OFF by default.** New `auto_update_mode` field:
  `faithful` (default, no LLM, no limit) vs `llm_polish` (per-page rewrite, premium). The
  old `auto_update_raw_mode` / `auto_update_max_content` fields are removed from the palette
  (columns kept for BC; `resolveMode()` reads `raw_mode` as a fallback).
- **Inspection download is now a capped manifest** (8 MB ceiling) summarising the indexed
  pages, not the uploaded payload (each page is its own vector-store file).
- **Verification:** `BoilerplateFilter` covered by `tests/Service/BoilerplateFilterTest.php`
  (3 cases, all green via standalone run; phpunit is not installed locally and is not run in
  CI); all files pass PHPStan level 5 and ECS. **Correction (2026-06-15):** the earlier claim
  that "ECS cannot run locally … a pre-existing environment issue" was wrong — the
  `CommentLengthFixer` crash is triggered by the branch's `@param array{…}` PHPDoc shapes
  (baseline files do not crash) and is now handled by skipping that fixer in `ecs.php`. See
  "Pre-merge review & hardening" above.

### Manual-only trigger (added post-implementation)
New `auto_update_trigger` field: **`scheduled`** (default, current behaviour, needs server
cron) vs **`manual`** — the sync runs only via the dashboard "Run sync now" button and needs
no server cron at all. For small/rarely-changing sites and hosts without reliable cron.
- Cron (`VectorStoreAutoUpdateCron`) skips `manual` configs; schedule fields hidden via a
  subpalette; dashboard suppresses cron-health alarms and shows a "manual only" indicator.
- Audit hardening of the no-cron path:
  - `dispatchRun()` now resets status to `error` (with a CLI-fallback message) if process
    spawning fails — e.g. `proc_open` disabled on shared hosting — so the button never sticks
    on `queued`.
  - `dispatchRun()` gained the same stale-run escape the cron has, so a crashed manual
    run can't lock the button forever (no cron exists to clear it in manual mode). *(Updated
    2026-06-15: the stale window is now the shared, heartbeat-refreshed `STALE_RUN_SECONDS`
    lease — see "Pre-merge review & hardening" above.)*
  - `compileAutoUpdateSchedule()` no longer overwrites the saved schedule while in manual mode
    (prevented a "* * * * *" / every-minute schedule if the user later switched back).

### Known limitations / follow-ups (from audit)
- **Very large first sync (thousands of pages):** uploads run sequentially with a short
  per-file ingestion check, so an initial full sync can take a long time (potentially hours).
  ~~may exceed the cron's stale-run guard, risking a concurrent second run that uploads
  duplicates~~ — **resolved 2026-06-15** by the heartbeat-refreshed run lease (see "Pre-merge
  review & hardening"): a long but healthy sync keeps its lease and cannot be raced by the
  cron or a manual click. Duration itself is unchanged; running the first sync via CLI
  (`contao:openai-vector-sync <id>`) is still recommended so it does not hold the Contao cron
  lock (and thus the heartbeat) for the whole duration. Future: file-batch uploads.
- **Rate limiting (429):** handled. Every OpenAI call in `VectorStoreFileSync` goes through a
  retry wrapper with exponential backoff (1-60 s, capped, 5 attempts) that honours the
  `Retry-After` header and also retries 503 / transient transport errors. If retries are
  exhausted the page is recorded as failed and re-tried on the next run (status `partial`).
- **Corpus-coupled cleaning:** because boilerplate detection is cross-page, changing the
  site nav can change many pages' cleaned output and trigger broader re-uploads. Correct, but
  worth noting for cost.
- `VectorStoreFileSync::purge()` exists for config-deletion cleanup but is not yet wired into
  `OpenAiConfigListener::deleteVectorStore`.
- **Memory at scale:** `readAllPages()` loads all in-scope `tl_search` rows into memory and the
  boilerplate analysis needs the whole corpus to compute cross-page frequencies. The filter was
  optimised to a two-pass design so it only retains the frequency map (peak ≈ corpus size, not
  2×), but a very large corpus (e.g. 10k pages of long text) still needs an adequate PHP
  `memory_limit` on the CLI worker. True streaming would require a disk-backed frequency pass
  (future work).
- **Files-per-store ceiling:** one file per page means a vector store created before Nov 2025
  caps at 10,000 files. Newer stores allow up to 100,000,000. For >10k-page sites, use a
  recent store (or a multi-store strategy).

### Scale verdict
Production-ready and professional for the typical target (up to a few thousand pages): correct,
incremental, self-healing, rate-limit-aware, with no content limits. For the upper end (≈10k+
pages) it works but warrants: a recent vector store (file ceiling), a generous CLI
`memory_limit`, and a CLI-run first sync (duration). Batch/parallel upload and disk-backed
de-dup are the identified optimisations for that tier.

---

## 1. Background & problem

The current pipeline (`src/Service/VectorStoreAutoUpdateService.php`):

```
contao:crawl → read tl_search (capped) → concat all pages into ONE string
            → ONE LLM "optimize" chat call → ONE .md file → upload to vector store
```

This is fundamentally limited and was producing partial output:

- **`buildLlmInput()` truncates** input at `auto_update_max_content` (default 100 000 chars)
  and `break`s at the first oversized page, **dropping every page after it**. Real case:
  30 selected pages = 572 826 chars; only **12 reached the LLM**; the first 120 k-char page
  (pid 14) aborted the loop, so 18 pages — including small ones — were silently dropped.
- **Single LLM call is inherently lossy** — it summarises and drops content; it cannot
  faithfully reproduce thousands of pages and hits token/cost ceilings.
- **One output file** loses per-page `url`/`title` provenance, hurting citations.
- **Full rebuild every run** — re-processes everything hourly even when nothing changed.

### Product mandate

This is a **paid feature** for large companies running an intranet / document pool in
Contao (potentially thousands of pages).

- **No character / page limits** on indexed content — never truncate or cap.
- The vector-store "base" must be **exceptional quality** for excellent chatbot answers.
- Boilerplate stripping is welcome **only if it never removes real page content**.

---

## 2. Target architecture

```
contao:crawl → read ALL in-scope pages from tl_search (uncapped)
            → safe cross-page boilerplate de-dup
            → one cleaned file PER PAGE (split only if > 2,000,000-char safety ceiling)
            → incremental diff vs stored state (content hash)
            → upload new/changed (one file per page, with attributes), delete removed
            → (optional) per-page LLM polish, default OFF
```

The vector store performs its own chunking + embeddings, so the lossy single-LLM funnel is
removed. This unlocks both "no limits" and "exceptional quality", and per-page files give
clean provenance for citations.

---

## 3. Confirmed OpenAI limits & API behaviour (verified 2026-06-14)

Sources: OpenAI platform docs — Retrieval / File search, Vector Stores API reference,
Vector store file batches API reference, Files API.

| Constraint | Value | Implication for us |
|---|---|---|
| Max file size | **512 MB** per file | A single page never approaches this. |
| Max tokens per file | **5,000,000** | A 130 k-char page ≈ ~35 k tokens — far below. So **one file = one page**; splitting is only a theoretical safety net. |
| Files attachable to a vector store (`file_search`) | **10,000** | Matters only for >10 k-page sites. Stores created since **Nov 2025 support up to 100,000,000 files**. Flagged for very large sites. |
| Project file storage | **2.5 TB** default | Non-issue for text. |
| Chunking (`static` strategy) | `max_chunk_size_tokens` default **800** (range 100–4096); `chunk_overlap_tokens` default **400** (must be ≤ ½ of max) | Use `auto` (default) initially; expose as advanced config later. |
| File `attributes` (metadata) | ≤ **16 keys**, string values ≤ **256 chars** | Store `url`, `title` (truncated to 256), `page_id`, `language`, `content_hash`. Usable for `file_search` attribute filtering. |
| File batches | up to **500 file_ids** per batch | Batch uploads for throughput; poll batch status. |
| Ingestion | **asynchronous** — file/batch status `in_progress` → `completed`/`failed` | Must poll and record per-file status. |
| Files upload purpose | `assistants` (current) still valid for vector stores | Keep `purpose=assistants`. |

**Key takeaway:** because each page fits comfortably in one file, the design is simply
"one file per page", attached individually with per-file attributes and async status
polling — no content limit anywhere. (Batch attach of ≤500 files remains an available
throughput optimisation for very large sites.)

---

## 4. Data model

New table **`tl_openai_vector_file`** — tracks the state of every uploaded file per config,
enabling incremental sync and orphan cleanup.

| Column | Type | Purpose |
|---|---|---|
| `id` | int PK auto | |
| `pid` | int (→ tl_openai_config.id) | owning config |
| `tstamp` | int | |
| `page_id` | int (→ tl_page.id) | source page (0 if not page-bound) |
| `url` | varchar(2048) | source URL (provenance / attribute) |
| `title` | varchar(512) | source title |
| `language` | varchar(5) | |
| `search_checksum` | varchar(32) | copy of `tl_search.checksum` (reference) |
| `content_hash` | varchar(64) | sha256 of the **final cleaned content actually uploaded** — source of truth for incremental diff |
| `chunk_index` | int default 0 | for the rare split page |
| `chunk_count` | int default 1 | |
| `openai_file_id` | varchar(255) | OpenAI file id |
| `bytes` | int | uploaded size |
| `status` | varchar(20) | `uploaded` / `failed` / `orphan` |
| `last_error` | text null | |

Indexes: `pid`, `(pid, page_id)`, `openai_file_id`.

> Why a separate `content_hash` and not just `tl_search.checksum`: boilerplate de-dup depends
> on the whole corpus, so a page's *cleaned* output can change even when its raw checksum did
> not (e.g. the nav changed site-wide). Hashing the final uploaded content is the correct
> incremental key.

---

## 5. Phased tasks

### Phase 0 — Foundations (schema, no behaviour change)
- [x] Add `contao/dca/tl_openai_vector_file.php` (read-only `DC_Table`, closed, not editable).
- [x] ~~Add Doctrine migration~~ **Not needed** — the table is created by Contao's Doctrine
      schema sync from the DCA `sql` definitions (same mechanism as `tl_openai_sync_log`; no
      migration file exists).
- [ ] Register backend module entry — **skipped (optional).** The table is internal state and
      is created by schema sync without a BE_MOD entry; left unregistered to keep the backend
      uncluttered. Trivial to add later if inspection is wanted.
- [x] Confirm Doctrine schema sync picks up the table (verified against Contao docs: the schema
      provider scans every bundle's `contao/dca/*.php`).

### Phase 1 — No-limit per-page upload (replaces the bulk funnel)
- [x] `readAllPages(int $configId): array` — same scoping as `readSearchIndex()` but **no
      `maxChars`**; returns `page_id, url, title, text, language, checksum` for every page.
- [x] New `VectorStoreFileSync` collaborator (or methods on the service) that:
  - [x] builds per-page document content (`# title` + body; URL kept **both inline** (`Quelle: <url>`, so retrieved chunks can be cited) **and** as a file attribute);
  - [x] uploads one file per page via `POST /v1/files` (`purpose=assistants`);
  - [x] attaches each file via **`POST /v1/vector_stores/{id}/files`** with `attributes`
        (`page_id`, `url`, `title`, `language`, `content_hash`, `chunk`). **Deviation:** uses
        single-file create (with an attributes-rejection fallback), not `file_batches` — batches
        remain a future throughput optimisation (see §0).
  - [x] polls each file's ingestion status (`completed`/`failed`/timeout); records per-file outcome.
  - [x] safety-net split: if a page ever exceeds the `MAX_FILE_CHARS` ceiling (2,000,000
        chars), split at paragraph boundaries into `chunk_index` parts — never truncate
        (expected to never trigger).
- [x] Reconcile: upload all pages, then delete vector-store files + OpenAI files no longer in scope.
- [x] Remove the char cap from the default path; `buildLlmInput()`/single-file
      upload/`generateDocument` bulk call are retired (kept only behind the optional polish toggle, Phase 4).
- [x] Persist counts to `tl_openai_sync_log` (`pages`, `files_uploaded`, `files_failed`, `bytes`).
- [x] `php -l` + `phpstan` (level 5) clean. **`ecs` cannot run locally** — its bundled
      `CommentLengthFixer` crashes on the project's single-line `/** */` docblock style
      (reproduced on untouched baseline files; pre-existing environment issue, see §0).

### Phase 2 — Safe boilerplate de-duplication
- [x] `BoilerplateFilter` service:
  - [x] segment each page (format-agnostic: split on newlines AND sentence/`\s{2,}`
        boundaries; normalise whitespace for comparison);
  - [x] compute cross-page segment frequency;
  - [x] strip only segments appearing on ≥ threshold fraction of pages (conservative
        default, e.g. 0.6, and/or min page count); unique content can never reach the threshold;
  - [x] return cleaned text per page + stats (segments removed, samples);
  - [x] never strip if the corpus is tiny (e.g. < 3 pages) — not enough signal.
- [x] Document the Contao `<!-- indexer::stop -->` marker in README as the guaranteed,
      customer-controlled chrome exclusion.
- [x] Log removed-block count + samples per run (auditable).
- [x] Unit test with synthetic corpus proving unique content survives and repeated chrome is removed.

### Phase 3 — Incremental sync via content hash
- [x] Load existing `tl_openai_vector_file` rows for the config into a `page_id → row` map.
- [x] Compute `content_hash` of each page's final cleaned content.
- [x] Upload only **new** (no row) or **changed** (`content_hash` differs) pages.
- [x] Delete files for pages **removed** from scope (and their OpenAI files + store attachment).
- [x] Update the state table transactionally after a successful run.
- [x] Sync log: `added`, `updated`, `removed`, `unchanged` counts.
- [x] First run after deploy: detach the legacy single bulk file (`auto_update_file_id`) and rebuild per-page.

### Phase 4 — Optional per-page LLM polish (premium, default OFF)
- [x] Repurpose/replace `auto_update_raw_mode` with a clear mode field:
      `faithful` (default, no LLM) vs `llm_polish` (per-page).
- [x] Per-page LLM call using the existing prompt (`VectorStoreDocumentPrompt`), one page per
      call → faithful (model cannot drop other pages).
- [x] Retry/backoff on 429 (+503/transport) honouring `Retry-After`; per-page failure falls
      back to the faithful cleaned text (never drop a page). **Note:** uploads are sequential,
      not concurrent — parallelism is a future throughput optimisation.
- [x] Record `tokens_in`/`tokens_out` aggregated in the sync log.
- [x] Config note: cost scales with page count; default stays OFF.

> Recommendation (agreed): keep this **optional and OFF by default**. With per-page upload +
> native chunking + boilerplate de-dup, the faithful cleaned text is already excellent and
> avoids any LLM-induced alteration. The polish is a nice-to-have for messy sources, not core.

### Phase 5 — Backend UX + cleanup
- [x] Update `tl_openai_sync_log` DCA: add/show `added/updated/removed/unchanged`,
      `files_uploaded/failed`, `bytes`; keep `tokens_*` (used only in polish mode).
- [x] Replace the single-`.md` download with a **capped manifest** (summary header + per-page
      content, 8 MB ceiling) for inspection; `downloadDocument()` renamed output to
      `vector-store-manifest_*.md`. (A separate per-page export was not added — the manifest
      already lists every page.)
- [x] **Deprecate `auto_update_max_content`**: removed from the palette; column kept (BC, no
      migration). `auto_update_raw_mode` likewise removed from the palette, kept for BC fallback.
- [x] Update help text / labels in the config + sync-log language files (EN/DE); char-cap
      field no longer shown.
- [x] README: document the new pipeline, the `indexer::stop` guidance, the modes, and limits.
- [x] Final `phpstan` pass (clean). `ecs` now also clean (see "Pre-merge review & hardening":
      the `CommentLengthFixer` crash was branch-specific and is skipped; the rest auto-fixed).

---

## 6. Cross-cutting concerns
- **Partial failure:** one page/file failing must never abort the rest — record per-file
  errors, mark the run `partial`, keep already-uploaded files.
- **Idempotency / resumability:** the state table makes re-runs converge; a crashed run is
  healed by the next sync.
- **Stale-run guard / run lease:** a live run refreshes `auto_update_last_run` every
  `HEARTBEAT_INTERVAL` (60 s); the cron (`isDue`) and manual dispatch (`dispatchRun`) treat a
  `running`/`queued` config as in-flight while that lease is younger than the shared
  `STALE_RUN_SECONDS` (900 s). This is the authoritative concurrency guard (replaced the old
  fixed 30-min `running`-only window on 2026-06-15).
- **Testing:** the bundle currently has **no test suite**. Add a minimal `tests/` for the
  pure logic (`BoilerplateFilter`, content-hash diff, scope resolution) — deterministic and
  high value. Wire `phpunit` dev dependency.
- **Very large sites (>10 k pages):** flag the 10 k files-per-store limit; recommend a
  vector store created Nov 2025+ (100 M files) or document a multi-store strategy. Out of
  scope for the first build but noted.

## 7. Open items to decide during implementation
1. Drop `auto_update_max_content` column vs keep-and-ignore.
2. Exact boilerplate threshold default + whether to expose it as an advanced config field.
3. Whether to expose chunking strategy (`max_chunk_size_tokens`) as advanced config now or later.
