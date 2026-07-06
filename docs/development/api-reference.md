# API Reference

## Extension Endpoints

- `POST /ai-chat/send`: sends one frontend chat message. Requires AJAX and CSRF token. Rate-limited: messages per minute per client IP (default 10, backend-configurable, `0` = off), 1 per 2 seconds per session, and a per-configuration daily cap (default 1000, backend-configurable, `0` = off); over-limit requests return HTTP `429`. See [Chat rate limiting and cost protection](../security/rate-limiting.md).
- `GET /ai-chat/history`: returns the current OpenAI conversation history for the visitor session. Requires AJAX.
- `GET /ai-chat/token`: returns a CSRF token for frontend requests. Rate-limited to 1 request per 10 seconds per session.
- `POST /contao/api-key-validate`: backend API key validation helper.
- `POST /contao/license-key-validate`: backend premium license validation helper.
- `%contao.backend.route_prefix%/vector-store-auto-update`: premium add-on status and manual sync dashboard.

## OpenAI APIs Used

- `GET /v1/models`
- `POST /v1/conversations`
- `GET /v1/conversations/{id}/items`
- `POST /v1/responses`
- `POST /v1/files`
- `DELETE /v1/files/{id}`
- `POST /v1/vector_stores`
- `POST /v1/vector_stores/{id}/files`
- `DELETE /v1/vector_stores/{id}`

The one-time 2.0 upgrade cleanup migration can call `DELETE /v1/assistants/{id}` to remove old remote Assistants created by 1.x. Runtime chat no longer uses the Assistants API.
