# OpenAI Setup

## API Key

Create an OpenAI API key in the OpenAI platform and enter it under **AI-TOOLS -> OpenAI Dashboard**. The backend validates the key against `GET /v1/models` when saving.

For production, prefer an environment variable:

```bash
OPENAI_API_KEY_1=sk-...
```

The suffix is the Contao configuration ID. A generic `OPENAI_API_KEY` is also supported as a fallback. See [API key management](../security/api-key-management.md).

## Runtime APIs

The extension uses:

- Conversations API for chat state
- Responses API for every user message
- Files API and Vector Stores for knowledge-base files

Prompts are local Contao records by default. You can optionally reference an OpenAI dashboard prompt with `prompt_id` and `prompt_version`; when set, that dashboard prompt replaces local instructions at runtime.

## Verify

After sending a frontend test message, check the OpenAI platform:

- **Logs -> Responses** should contain the response call.
- **Logs -> Conversations** should contain the conversation state.
- The response payload should show the selected model, output settings and either local instructions or a prompt reference.

For upgraded 1.x installations, see [Upgrading from 1.x](../development/troubleshooting.md#upgrading-from-1x).
