# Prompts

Prompts are stored locally in `tl_openai_prompts`. The extension does not create remote OpenAI Assistant objects in v2.x.

## Local Prompt Mode

Create one active prompt under **AI-TOOLS -> OpenAI Dashboard -> Prompts** and set:

- name
- model or manual model name
- system instructions
- max tokens, temperature and `top_p`

At runtime these values are sent with each `POST /v1/responses` call. `max_tokens` is mapped to OpenAI's `max_output_tokens`.

## OpenAI Dashboard Prompt Mode

If you manage a prompt in the OpenAI dashboard, paste its `prompt_id` into the Contao prompt record. `prompt_version` is optional.

When `prompt_id` is set, the dashboard prompt replaces local system instructions. The Contao model and output settings are still sent with the request.

## Validation

Model compatibility is checked on save with a minimal Responses API ping. Prompt deletion is local only; no remote OpenAI object is deleted.
