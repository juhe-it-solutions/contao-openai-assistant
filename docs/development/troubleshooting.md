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
- The first sync is always manual (dashboard button or CLI command); scheduled cron runs apply from the second sync onward.
- Hosts that disable `proc_open` cannot dispatch manual syncs from the backend; run the CLI command instead.

## Development Checks

For local development, run:

```bash
composer validate
vendor/bin/ecs check
vendor/bin/phpstan analyse src/ --level=5
composer audit
```
