# Quick Start

1. Install the extension and run migrations:

   ```bash
   composer require juhe-it-solutions/contao-openai-assistant
   php bin/console contao:migrate
   ```

2. Create an OpenAI API key in the OpenAI platform.

3. In Contao, open **AI-TOOLS -> OpenAI Dashboard** and create one configuration.

4. Upload knowledge-base files if the chatbot should answer from your documents or website information. Supported backend uploads are `pdf`, `txt`, `md`, `docx`, `pptx` and `json`.

5. Create one active prompt with model, instructions and output settings. Optionally set `prompt_id` and `prompt_version` to use a prompt maintained in the OpenAI dashboard.

6. Create a frontend module of type **AI tools -> AI-Chatbot** and add it to the page layout or a content element.

## Verify

- The backend shows **AI-TOOLS -> OpenAI Dashboard**.
- The frontend module appears on the selected page.
- A test message creates entries in OpenAI **Logs -> Responses** and **Logs -> Conversations**.

For deeper setup details, see [OpenAI setup](configuration/openai-setup.md), [Prompts](configuration/prompts.md) and [Troubleshooting](development/troubleshooting.md).
