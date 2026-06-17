# Requirements

## Runtime

- PHP 8.2 or newer
- Contao 5.3 or newer
- `symfony/http-client`
- Composer-managed installation

The extension follows Contao's normal database and web-server requirements. Use HTTPS in production because visitors send chat messages through your site and the extension calls OpenAI with your API key.

## OpenAI

The configured key needs access to:

- `GET /v1/models`
- `POST /v1/conversations`
- `GET /v1/conversations/{id}/items`
- `POST /v1/responses`
- Files API
- Vector Stores / File Search

Uploaded knowledge-base files are selected from Contao's file manager and sent to OpenAI. The current upload field allows `pdf`, `txt`, `md`, `docx`, `pptx` and `json`.

## Development

Development dependencies are declared in `composer.json`:

- `contao/easy-coding-standard`
- `phpstan/phpstan`
- `phpunit/phpunit`

The repository intentionally does not track `composer.lock`, because this is a reusable Contao bundle rather than an application.
