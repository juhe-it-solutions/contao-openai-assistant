# Troubleshooting

## Upgrading From 1.x

Version 2.0 replaces the OpenAI Assistants API with OpenAI Responses and Conversations.

What changes:

- Chat messages now use `POST /v1/responses`.
- Chat state now uses OpenAI Conversations and the session key `openai_conversation_id`.
- Remote OpenAI Assistants are no longer runtime objects.
- Local prompt records live in `tl_openai_prompts`.

The upgrade runs two migrations:

- `Version20260416000000RenamePromptsTable` renames `tl_openai_assistants` to `tl_openai_prompts` and adds `prompt_id` / `prompt_version`.
- `Version20260416000001CleanupOrphanAssistants` attempts to delete old remote `asst_...` records and clears local legacy references.

There is no supported downgrade path to 1.x after the cleanup migration. Restore a pre-upgrade database backup if you must roll back.

## API Key Problems

If validation fails:

- Check that the key has not been copied with spaces.
- Prefer `OPENAI_API_KEY_{configId}` in production.
- Confirm the key can access `/v1/models`, Responses, Conversations, Files and Vector Stores.
- Clear the Contao cache after changing environment variables.

## Chatbot Does Not Answer

Check these in order:

- There is one OpenAI configuration.
- There is one active prompt for that configuration.
- The selected model passes save-time validation.
- The OpenAI account has credits and the required API access.
- The Contao log does not contain OpenAI HTTP errors.

## Chatbot Has No Knowledge-Base Answers

Upload at least one supported file under **OpenAI Dashboard -> Files**. The vector store is created and populated from those uploads.

The premium add-on also requires an existing vector store. If no file has ever been uploaded, automatic sync has no vector store to update.

## File Upload Shows "File Not Found"

The extension resolves file paths through Contao's `%contao.web_dir%` parameter. If a selected file cannot be found:

- Clear the cache after changing document-root settings.
- Confirm the file exists below the resolved Contao web directory.
- Re-select the file in the backend if the file reference is stale.

## Premium Add-On Sync

Automatic vector-store updates require a valid premium license. Details are intentionally kept in the [premium add-on help pages](https://licenses.juhe-it-solutions.at/en/openai-assistant/help).

Useful checks:

- A license key is saved and validates successfully.
- At least one file upload has created the OpenAI vector store.
- The selected pages are indexable by Contao's search indexer.
- Scheduled mode requires a real CLI cron running `contao:cron`; web-only cron (triggered by page visits) is not sufficient — the auto-sync job skips web scope. The dashboard shows "Not configured" if only web cron is detected. Manual mode uses the backend trigger.
- The cron does **not** have to be minutely. A Contao cron job is re-evaluated whenever `contao:cron` runs, so a host that only offers a 5-, 15- or 30-minute cron works fine — the sync simply starts at the next possible tick. `CronHealthService::IDLE_GRACE_SECONDS` (30 min) is sized for exactly those hosts.
- The first sync is always manual (dashboard button or CLI command); scheduled cron runs apply from the second sync onward.
- On a Contao installed from the Symfony skeleton the console is `bin/console`, not `vendor/bin/contao-console`. The Managed Edition path is what the dashboard shows.

### Hosts that disable `proc_open`

Two different things need to spawn a process, and they fail independently:

- **The backend "Run sync now" button.** Detected up front — the dashboard warns and points to the CLI command. Most hosts disable `proc_open` for PHP-FPM only, so this is the common case.
- **The crawl inside a run.** `spawnCrawl()` starts `contao:crawl` as a subprocess, so a host that also disables `proc_open` on the **CLI** breaks scheduled runs too, not just the button.

For the second case, set **"Suchindex vor der Synchronisierung aktualisieren"** to
**Nie** (`auto_update_crawl_mode = never`). The run then reads whatever is already in
`tl_search` and spawns nothing at all, so it works on a fully locked-down host. The
search index must then be kept current by something else — the site's own visitor
traffic indexes pages via `SearchIndexListener`, or a separate `contao:crawl` cron job.

## Sync Fails With "Failed to open stream" in `var/cache`

A run that ends with a wall of PHP warnings about a missing file under
`var/cache/<env>/Container<hash>/` was interrupted by a container rebuild, not by
anything on the website:

```
Warning: require(.../var/cache/prod/ContainerXE6omHe/getFosHttpCache_....php):
Failed to open stream: No such file or directory
```

Symfony keeps the compiled container in `var/cache/<env>/Container<hash>/` and requires
those service files lazily. Rebuilding the container writes a **new** hash directory and
deletes the old one — so a `contao:crawl` subprocess that is still running loses the
directory underneath it and dies on the next `require`. The service named in the error is
meaningless; it is simply whichever one was needed first afterwards.

The backend reports this case in plain words ("the server rebuilt its cache …"); the full
output goes to the system log. Nothing is indexed and nothing in the vector store changes,
so it is always safe to start the synchronisation again.

Two causes, in order of likelihood:

1. **The site was updated or deployed while a sync was running** — a Contao Manager action,
   `composer install`, or a cache clear. Nothing to fix; run the sync again afterwards.
2. **The CLI PHP version differs from the website's.** The sync spawns `contao:crawl`
   through Contao's `ProcessUtil`, which resolves the interpreter with Symfony's
   `PhpExecutableFinder`. That returns `PHP_BINARY` only for the `cli`/`cli-server`/`phpdbg`
   SAPIs — so a sync started from the **backend button** runs under FPM, falls through to
   searching `$PATH`, and can pick a different PHP than the site runs. Both SAPIs share
   `var/cache`, so each rebuilds the container the other just built, in a loop.

To check the second cause, compare the CLI version with the site's configured version:

```bash
php -v                    # the CLI in $PATH
```

If they differ, point the subprocess at the site's PHP. `PhpExecutableFinder` honours the
`PHP_PATH` environment variable, so setting it in the PHP-FPM pool fixes it for every
Contao subprocess, not just this extension's:

```ini
; PHP-FPM pool config (ISPConfig: Sites → Options → PHP directives)
env[PHP_PATH] = /opt/php-8.3/bin/php   ; the version the SITE runs
```

Reload PHP-FPM afterwards. Note that running the kernel under a PHP version the Contao
release does not support (e.g. Contao 5.3 under PHP 8.5) causes trouble beyond this cache
race, so it is worth aligning regardless.

## Development Checks

For local development, run:

```bash
composer validate
vendor/bin/ecs check
vendor/bin/phpstan analyse src/ --level=5
composer audit
```
