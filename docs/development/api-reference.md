# API Reference

This document describes the extension's own HTTP endpoints and the OpenAI endpoints it uses internally in v2.x.

## Extension Endpoints

### `POST /ai-chat/send`

Sends one user message from the frontend chat widget.

- Requires AJAX request
- Requires CSRF token (`REQUEST_TOKEN` or `X-CSRF-Token`)
- Rate limited by session timestamp
- Returns JSON:
  - success: `{ "reply": "...", "timestamp": "YYYY-MM-DD HH:MM:SS" }`
  - error: `{ "error": "..." }`

### `GET /ai-chat/history`

Returns current conversation history for the active frontend session.

- Requires AJAX request
- Uses session key `openai_conversation_id`
- Returns JSON:
  - `{ "history": [ { "role": "user|assistant", "content": "...", "timestamp": "..." } ] }`

### `GET /ai-chat/token`

Returns a CSRF token for frontend requests.

- Rate limited by session timestamp
- Returns JSON: `{ "token": "..." }`

### `POST /contao/api-key-validate`

Backend helper endpoint used by the "Check key" button in OpenAI config.

## OpenAI Endpoints Used Internally

### Runtime Chat

- `POST /v1/conversations` to create conversation state (once per session)
- `POST /v1/responses` to process user turns
- `GET /v1/conversations/{id}/items` to rebuild chat history

### Model Validation

- `GET /v1/models` for model listing
- `POST /v1/responses` (minimal ping) for save-time model compatibility validation

### Files and Vector Stores

- `POST /v1/files`
- `DELETE /v1/files/{id}`
- `POST /v1/vector_stores`
- `POST /v1/vector_stores/{id}/files`
- `DELETE /v1/vector_stores/{id}`

Note: as of v2.0, vector store endpoints still use `OpenAI-Beta: assistants=v2` where required by OpenAI. Regular Responses API calls do not.

### One-Time Upgrade Cleanup Migration

- `DELETE /v1/assistants/{id}` is called only by migration `Version20260416000001CleanupOrphanAssistants` to remove legacy orphaned Assistants from pre-2.0 installations.
- If no valid API key can be resolved in migration CLI context, this remote delete call is skipped; any remaining legacy assistants must be removed manually in the OpenAI dashboard.
