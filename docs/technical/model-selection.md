# Model Selection

Prompt model options are loaded from OpenAI's `/v1/models` endpoint for the current configuration key. The backend also offers a manual model field for model names that are not listed yet.

## Validation

The selected model is validated only when the prompt is saved. Validation sends a small `POST /v1/responses` request with `input: "ping"`, `max_output_tokens: 16` and `store: false`.

This replaces the old v1.x validation through temporary Assistants. The Assistants API is not used for prompt validation anymore.

## Fallback

If model listing fails, the manual model option is still available. Save-time validation remains the source of truth because OpenAI determines which models are accepted by the Responses API for the current account.
