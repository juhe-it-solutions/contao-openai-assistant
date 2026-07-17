# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.3] - 2026-07-18

### Added
- **New prompt setting "Search results per question"** (default: 8, range 1-20). Controls how many text sections from the synced website content the AI may read per answer. Lower values keep answers focused and reduce cost per question; higher values help with broad questions that combine content from many pages (e.g. intranet research). Previously every question always retrieved up to 20 sections.
- A pseudonymous per-visitor identifier (SHA-256 hash of the session id, not reversible) is sent to OpenAI as `safety_identifier`, so potential abuse is attributed to a single visitor instead of the site owner's whole API key.

### Fixed
- **Long chats no longer fail with "Service temporarily unavailable".** Once the stored conversation (including the retrieved website excerpts of earlier turns) outgrew the model's context window, OpenAI rejected every further question with HTTP 400 - especially quickly on smaller models such as gpt-4o-mini. Responses are now requested with `truncation: auto`, so OpenAI trims the oldest turns instead of failing, and if a request is still rejected the chat transparently continues on a fresh conversation instead of showing an error.
- **A stale conversation no longer breaks the chat until the session expires.** If the stored conversation is gone on OpenAI's side (deleted, expired, or the API key was switched to another account), the chat now transparently continues on a fresh conversation. Switching the active configuration also proactively starts a new conversation instead of producing "not found" errors.
- **Models that reject `temperature`/`top_p` (e.g. reasoning models) work now.** The rejected parameter is stripped and the call repeated; the rejection is remembered per model so only the first message after a model switch pays an extra (unbilled) round-trip. The model dropdown also no longer offers models that cannot power a text chat (audio, image, video, embedding, moderation).
- **Reloading a long chat now shows the latest messages.** The history restore previously loaded the oldest 100 conversation items, so long conversations reappeared without their newest turns.
- Transient OpenAI failures (connection failures before the request was processed, HTTP 429/503) are retried once after a short backoff instead of immediately showing an error. Timeouts are deliberately not retried to avoid processing a message twice.
- The Responses call now has a wall-clock time cap (previously only an inactivity timeout), and the chat widget shows a friendly "taking too long" message (DE/EN) after 2 minutes instead of a spinner that runs until a proxy gives up.

### Changed
- CI now tests against both supported Contao lines: 5.3 LTS on PHP 8.2 and 5.7 on PHP 8.3 (the end-of-life 5.4-5.6 lines are no longer resolved into any CI job).

### Notes
- Run `contao:migrate` after updating (new `tl_openai_prompts.max_num_results` column). The chat itself works before the migration (the new setting then uses its default of 8), but editing prompts in the backend requires the migrated schema.
- Visitors chatting during the upgrade keep their running conversation - existing sessions are adopted, not reset.

## [2.1.2] - 2026-07-18

### Fixed
- **API keys stored before 2.1.0 could be unreadable for the CLI sync after an upgrade** ("No usable OpenAI API key"). Stored keys are now automatically re-encrypted with the current key on first use - no manual re-entry needed. Keys in the pre-1.0 base64 format are also migrated correctly.
- **Sync setup checks now match what the sync actually does.** The search-index check is scoped to the selected pages instead of the whole index, and an empty search index no longer blocks the sync (each run starts the crawler itself, so this is now a non-blocking note).
- The sync dashboard warns (and blocks the manual run) when the selected pages span more than one website domain - one license covers one domain.
- Sync errors and setup hints now name the actual cause (pages missing from the search index, missing root domain name, unusable API key) and explain how to fix it.
- The first-sync hint in the vector store sync settings was partly invisible in the Contao backend; it is visible again and links to the dashboard's setup checklist.
- The prompt template field is disabled in "faithful" indexing mode, where it has no effect; the stored template is kept and becomes editable again in "AI-polished" mode.

### Changed
- The sync dashboard shows the license tier badge in all active license states (trial, grace period, payment problem, cancelled but still running).
- Backend text and style polish: sync history labels ("Last 10 syncs" / "Full history"), hint that the initial upload can be deleted after the first sync, theme-aware chat scrollbars, dash cleanup in translations.

## [2.1.1] - 2026-07-16

### Changed
- Pinned the `symplify/easy-coding-standard` dev dependency to 13.2.3 to keep CI code-style checks stable.
- GitHub release notes are now generated from CHANGELOG.md by the release workflow.

No functional changes for users of the extension; identical runtime behaviour to 2.1.0.

## [2.1.0] - 2026-07-16

### Added
- **Premium add-on: automatic vector-store updates.** Keeps the OpenAI vector store in sync with selected Contao pages (manual or scheduled runs, backend status dashboard). Requires a [premium subscription](https://licenses.juhe-it-solutions.at/en/openai-assistant/help).
- **Chat rate limiting - on by default after upgrade.** Two new settings in the OpenAI configuration: per-IP limit (`chat_ip_rate_limit`, default 10/minute) and daily message cap (`chat_daily_limit`, default 1000/day); `0` disables. Raise or disable the IP limit on intranets/NAT where many users share one IP. See [docs/security/rate-limiting.md](docs/security/rate-limiting.md).
- **Link shortening - on by default after upgrade.** New AI-Chatbot module checkbox **Shorten plain URLs** (`tl_module.shorten_urls`, default on): plain URLs in bot answers are rendered as short localized labels ("Download" / "Seite aufrufen" / "Visit page") instead of the full URL. The complete URL stays in `href` and `title`; Markdown links with descriptive text keep it and show the URL as tooltip. Disable the checkbox to restore full-URL rendering. See [docs/features/link-shortening.md](docs/features/link-shortening.md).

### Changed
- **Licensing:** the core extension remains LGPL-3.0-or-later; the new premium add-on files are proprietary (see [`LICENSE-PREMIUM`](LICENSE-PREMIUM)). All earlier releases remain entirely LGPL.
- **Phone autolinking** in chat answers now requires a leading `+` or a phone cue ("Tel.", "Rufen Sie an", …) before the number, so invoice numbers, ISBNs, and dates are no longer turned into `tel:` links.

### Fixed
- **Frontend chat links:** more robust rendering of model-mangled URLs (CJK-bracket-wrapped or decorated URLs, malformed Markdown echoes, line-wrapped URLs); repeated identical links are no longer collapsed into one; URL credentials never appear in tooltips or screen-reader labels.

### Security
- Frontend chat messages are HTML-escaped before formatting (XSS hardening).
- **License validation robustness:** rate-limit (429) and server-error (5xx) responses from the licensing server are now treated as temporary outages covered by the seven-day grace period instead of deactivating a valid license; entitlement data is only accepted from well-formed 2xx responses.

### Notes
- Run `contao:migrate` after the update (new database columns).

## [2.0.2] - 2026-07-01

### Fixed
- **Frontend chat links:** Models sometimes wrap long URLs with a newline at `?`, `&`, `/`, `=` or `#`; after the newline-to-`<br>` conversion these breakpoints landed inside the link text and broke auto-linking. `<br>` is now allowed at those breakpoints and stripped from the resulting `href`.

## [2.0.1] - 2026-06-11

### Fixed
- **Frontend chat links:** Improved chatbot message link rendering for Markdown links, angle-bracket URLs, `www.` links, `mailto:` and `tel:` links, and URLs containing query strings, fragments, or balanced parentheses. Autolinking now avoids modifying already-rendered anchors and keeps trailing sentence punctuation outside clickable links.

## [2.0.0] - 2026-04-16

> ⚠️ **Breaking change release.** This version replaces the OpenAI Assistants API (which OpenAI is sunsetting on **August 26, 2026**) with the **Responses API** and **Conversations API**. The upgrade is automated via two migrations (table rename + orphan cleanup), but there is no downgrade path back to 1.x because remote OpenAI Assistants are deleted during the upgrade. See the [Upgrading from 1.x](docs/development/troubleshooting.md#upgrading-from-1x) section for details.

### Added
- New `tl_openai_prompts.prompt_id` (VARCHAR 128) and `tl_openai_prompts.prompt_version` (VARCHAR 32) columns: you can optionally reference a prompt managed in the OpenAI dashboard. When set, the dashboard-managed prompt overrides the local `Instructions` field.
- New `src/Service/OpenAiResponder.php` service that encapsulates the Responses API runtime: creating conversations, sending messages, retrieving conversation items, and clearing sessions.
- New `src/Service/EncryptionService.php` centralises API key encrypt/decrypt/validate logic. Supports `OPENAI_API_KEY_{configId}` environment variable override via `getApiKeyForConfig()`.
- Conversation history retrieval for the frontend chatbot: chat state is rehydrated from `GET /v1/conversations/{id}/items` on page reload instead of relying on `/v1/threads/{id}/messages`.
- Full German + English localisation for the new "Prompts" terminology and the new `prompt_id` / `prompt_version` fields.

### Changed
- Runtime migrated from `POST /v1/threads/{id}/runs` to `POST /v1/responses`. Each request carries the conversation id, the prompt configuration (model, instructions or `prompt` reference, `temperature`, `top_p`, `max_output_tokens`), and the File Search tool when a vector store is attached.
- Session storage key renamed from `openai_thread_id` to `openai_conversation_id`. Legacy `openai_thread_id` keys are silently unset on first request after upgrade.
- `OpenAiPromptsListener::validateModelViaApi()` now validates model compatibility by sending a minimal `POST /v1/responses` ping (`input: "ping"`, `max_output_tokens: 16`, `store: false`) instead of creating and deleting a temporary Assistant.
- Database table `tl_openai_assistants` renamed to `tl_openai_prompts` (migration `Version20260416000000RenamePromptsTable`).
- Backend DCA, language files, and navigation labels now say "Prompts" instead of "Assistants".
- `OpenAiConfigListener::deleteVectorStore()` no longer deletes remote Assistants when a config is removed; prompts are purely local now. Vector-store-and-files cascade cleanup is unchanged.
- Listener service renamed: `OpenAiAssistantsListener` → `OpenAiPromptsListener`. DCA callback tags updated to target `tl_openai_prompts`.
- `OpenAiFilesListener` no longer sends the `OpenAI-Beta: assistants=v2` header on `DELETE /v1/files/{id}` calls (it's only kept on vector store endpoints that still require it).

### Removed
- All runtime calls to the OpenAI Assistants API:
  - `POST /v1/assistants`
  - `POST /v1/assistants/{id}`
  - `DELETE /v1/assistants/{id}` *(still used once by the cleanup migration - last allowed usage)*
  - `POST /v1/threads`, `POST /v1/threads/{id}/messages`, `POST /v1/threads/{id}/runs`, `GET /v1/threads/{id}/messages`
- `src/Service/OpenAiAssistant.php` is no longer the runtime implementation; a deprecated BC shim now forwards to `OpenAiResponder` to keep 1.x custom integrations working until 2.1.
- The "Sync with OpenAI" button and related `createOrUpdateAssistant` / `deleteAssistant` DCA actions - prompts are local and do not need remote synchronisation.
- `config.onsubmit` / `config.ondelete` DCA callbacks that previously created / deleted remote Assistants.

### Migrated
- **Orphan Assistant cleanup** (`Version20260416000001CleanupOrphanAssistants`): on upgrade, every `tl_openai_prompts` row with a non-empty `openai_assistant_id` triggers a `DELETE /v1/assistants/{id}` on the OpenAI platform (still authorised for cleanup during the sunset window). The local `openai_assistant_id` column is then cleared. HTTP 2xx / 404 / 410 / 401 are all treated as "gone". The migration never throws on HTTP errors and writes a summary (`deleted` / `skipped` / `failed` counts) into the migration result.
- Database table rename + new columns (`Version20260416000000RenamePromptsTable`): idempotent, re-runnable safely.

### Notes
- Users with active chat sessions at upgrade time will see a fresh, empty conversation on their next message - the legacy thread ids were session-scoped in v1.x anyway.
- Runtime API key resolution prefers `OPENAI_API_KEY_{configId}` over DB-encrypted keys. (The one-time orphan cleanup migration reads the DB-stored key for 1.x compatibility.)
- **Important for upgrades from 1.x:** The orphan-assistant cleanup runs in CLI context. If no valid API key can be resolved there (e.g. encrypted key cannot be decrypted in that environment), the migration still clears local legacy references but cannot remove the remote Assistant. In that case, any already existing "OpenAI assistant" must be deleted manually in the OpenAI platform dashboard.
- No changes to files, vector stores, or uploaded documents - these continue to live on OpenAI's platform and keep working with the File Search tool.

## [1.1.3] - 2026-03-04

### Fixed
- **Frontend chat links:** Chatbot output could contain links whose `href` ended with a trailing dot (e.g. `https://example.com/page.html.`), breaking or misdirecting clicks. All `href` values are now sanitized so that trailing dots are stripped before display.
- **Chat history order:** Thread messages are now requested from the OpenAI API with `order=asc`, so conversation history displays in chronological order after page navigation or reload.

## [1.1.2] - 2026-02-24

### Fixed
- **Frontend chat links:** LLM sometimes appended "br" to http(s) link URLs and link text (e.g. `.htmlbr` instead of `.html`). A post-processing step now strips trailing "br" from http(s) links only; mailto: and tel: links are unchanged.

## [1.1.1] - 2026-02-24

### Added
- **Frontend i18n for the AI chat module:** All user-visible strings (placeholder, buttons, labels, titles, errors, disclaimer) are now translated based on the browser’s preferred language. German and English are supported via `contao/languages/de/mod_ai_chat.php` and `contao/languages/en/mod_ai_chat.php`. The controller reads `Accept-Language` from the main request, parses it in priority order, and loads the matching language file; the template and JavaScript use server-provided labels and a small JSON map for client-side strings. Unsupported locales fall back to English. Module-specific titles and messages from the backend still override the translated defaults.

### Changed
- Language detection now respects the order of the `Accept-Language` header (e.g. `en,de;q=0.9` correctly yields English when English is listed first).
- `Accept-Language` is read from the main request via `RequestStack::getMainRequest()` so the chat language follows the visitor’s browser even when the module is rendered in a fragment sub-request.

### Fixed
- **Frontend chat links:** Trailing `<` or `>` could appear in link `href` values and link text, breaking or misdisplaying links. URLs are now sanitized when turning plain URLs into links (strip `<`/`>` from captured URL), all `href="..."` values are cleaned of `<`/`>` in a final pass, and a stray `>` immediately after `</a>` (e.g. from angle-bracket notation or model output) is removed.

### Notes
- No database migration required. Clear frontend cache after update if needed.

## [1.1.0] - 2026-02-18

### Added
- Backend "Key prüfen" button for OpenAI config API key field: validate key before save (works in Contao 5.3 and 5.7).
- Dedicated backend JS asset `backend-api-key-check.js` as fallback for button binding.
- Backend CSS for API key check wrapper (placement below input, spinner, result message).

### Changed
- OpenAI config DCA: API key field now uses `xlabel` callback instead of `wizard` for reliable rendering in Contao 5.7.
- `OpenAiConfigListener::apiKeyWizard()`: outputs HTML + data attributes and inline script so the button works without depending on global backend JS in all Contao versions.
- Button is placed below the API key input in both 5.3 and 5.7; input lookup supports `ctrl_<field>`, `<field>`, and `input[name="..."]` for compatibility.
- Removed `fields.api_key.wizard` service callback tag from `config/services.yaml` (wizard registration is via DCA only).

### Fixed
- "Key prüfen" button not visible in Contao 5.7.0 (wizard callback no longer used for password widget in 5.7).
- Button overlapping the API key input in Contao 5.7; wrapper is moved below the field and styled with clear spacing/z-index.
- Button click having no effect when backend JS did not load; inline script in widget ensures validation runs in both 5.3 and 5.7.

### Notes
- No database migration required. Clear backend cache after update.

## [1.0.8] - 2025-09-25

### Changed
- Use configured web directory parameter (`%contao.web_dir%`) to resolve absolute file paths instead of hardcoding `public/`.
- Preserve absolute `%contao.web_dir%` values and only prefix with `%kernel.project_dir%` when relative.

### Fixed
- "File not found" errors on systems with non-default web roots (e.g., custom doc roots or legacy `web/`).
- Improved user-facing error messages for missing files, including resolved web root and attempted absolute path.

### Notes
- No database migration required. Clear cache after update so the container picks up the new service argument.

## [1.0.7] - 2025-08-23

### Fixed
- **CRITICAL**: Database migration issue with disclaimer_text column causing "Data truncated" error in MySQL
- Updated DCA configuration to use proper Doctrine schema representation for Contao 5.3+
- Removed database default values for TEXT columns (not supported in MySQL)
- Enhanced migration system to handle TEXT columns properly without database defaults

## [1.0.6] - 2025-01-27

### Added
- Set default value for disclaimer in frontend chatbot
- Auto-focus on chatbot input field for better user experience

### Changed
- Improved release script with better error handling and validation

## [1.0.5] - 2025-08-23

### Added
- Enhanced frontend module with improved user experience
- New documentation structure with feature guides
- Migration system for database schema updates

### Changed
- Updated module templates and styling for better responsiveness
- Improved JavaScript functionality for chat interactions
- Enhanced language files with better translations
- Updated PHPStan configuration for stricter type checking

### Fixed
- Various code style and formatting improvements
- Enhanced documentation clarity and structure

## [1.0.4] - 2025-08-13

### Fixed
- Load services via `AbstractBundle::loadExtension` with `ContainerConfigurator`; fixes service loading after v1.0.3 where services might not have been registered properly.

## [1.0.3] - 2025-08-13

### Added
- Surface assistant failure causes and bind vector store per run
- Add `.gitattributes`
- Add project homepage
- CI/CD pipeline documentation and quick reference

### Changed
- Update to PHPStan 2.x, adjust configuration and code accordingly
- Preserve system instructions exactly as entered (decode entities; preserve quotes/brackets)
- Adapt regex to prettify bot answers
- Update CI/CD workflows and documentation

### Fixed
- Code style issues and ECS workflow inconsistencies
- Default value of DCA field `top_p`
- Mobile: ensure chat window is collapsed by default on small screens
- Various CI/CD pipeline fixes; remove invalid `--dry-run` flag in ECS check
- Add explicit nullable type hint for PHP 8.4 compatibility

### Removed
- `composer.lock` from the repository

## [1.0.2] - 2025-07-07

### Added
- Complete CI/CD pipeline implementation and production readiness

### Changed
- Prevent auto-focus on mobile devices to avoid unwanted keyboard popups

### Removed
- Obsolete package metadata and logo/preview files from `contao` directory (moved to package-metadata repository)
- One GitHub Actions workflow to simplify development

## [1.0.1] - 2025-07-05

### Added
- Optimized package metadata, company logo, and preview image for the Contao extension marketplace

### Changed
- Bump internal version metadata to 1.0.1

### Fixed
- Critical CSS issue on mobile devices

### Removed
- Unnecessary `contao/README.md` file

## [1.0.0] - 2025-07-03

### Added
- Initial release of Contao OpenAI Assistant Bundle
- OpenAI Assistant integration with backend management
- Frontend chatbot with customizable styling
- File upload support for knowledge base
- Secure API key management with encryption
- Vector store integration for file processing
- Model selection with validation
- CSRF protection and security features
- Responsive design with theme support
- Comprehensive documentation and guides

### Changed
- CI/CD pipeline implementation with GitHub Actions, PHP 8.2 testing, code quality checks (ECS/PHPStan), security scanning, and automated release workflow
- Simplified testing approach focusing on code quality, formatting, and security
- PHP 8.2+ compatibility

### Security
- API key encryption using AES-256-CBC
- CSRF token validation for all forms
- Input sanitization and validation
- Secure file upload handling

- Environment variable support for API keys
