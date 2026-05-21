# Prompt Configuration

This guide explains how to configure prompts in version 2.x of the extension.

## Overview

In 2.x, prompts are stored locally in Contao (`tl_openai_prompts`) and used at runtime with the OpenAI Responses API.

- No remote Assistant object is created.
- The extension sends prompt settings on each `POST /v1/responses` request.
- Conversation state is managed via the Conversations API.

## Create a Prompt

1. Go to **AI-TOOLS → OpenAI Dashboard**.
2. Open the **Prompts** child table for your configuration.
3. Create one prompt record.
4. Set:
   - `name`
   - `model` (or `model_manual`)
   - `system_instructions`
   - `temperature`
   - `top_p`
   - `max_tokens` (mapped to `max_output_tokens` for Responses API calls)
5. Set status to `active`.

## Optional: Use an OpenAI Dashboard Prompt

If you manage prompts in platform.openai.com, you can reference one from Contao:

- `prompt_id`: required for dashboard prompt usage
- `prompt_version`: optional, pin to a specific version

When `prompt_id` is set, it takes precedence over local `system_instructions`. Other request settings from Contao (model, max output tokens, temperature, top_p) are still sent at runtime.

## Validation Behavior

- Model compatibility is validated on save via a minimal `POST /v1/responses` ping.
- Validation no longer uses the deprecated Assistants API.

## Notes

- One prompt record is allowed per config (enforced by listener redirect logic).
- Prompt delete is local only; no remote OpenAI delete is performed.

## Verify in OpenAI Dashboard

After sending one chat message:

- Check **Logs -> Responses** and **Logs -> Conversations**.
- Open a response item and inspect effective runtime properties:
  - model
  - max output tokens
  - temperature
  - top_p
  - instructions or prompt reference (`prompt_id`/version)
