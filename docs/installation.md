# Installation

## Requirements

- Contao 5.3 or newer
- PHP 8.2 or newer
- Composer
- OpenAI API key with access to Responses, Conversations, Files and Vector Stores

## Install

Use Contao Manager or Composer:

```bash
composer require juhe-it-solutions/contao-openai-assistant
```

Run the database migration afterwards:

```bash
php bin/console contao:migrate
```

Clear the cache if your deployment process does not already do this.

## First Setup

1. Open **AI-TOOLS -> OpenAI Dashboard** in the Contao backend.
2. Create the OpenAI configuration and validate the API key.
3. Upload at least one knowledge-base file if the chatbot should use File Search.
4. Create one active prompt.
5. Create a frontend module of type **AI tools -> AI-Chatbot** and add it to your layout or page.

For production, prefer `OPENAI_API_KEY_{configId}` environment variables over storing the key only in the database. See [API key management](security/api-key-management.md).

## Upgrade From 1.x

Version 2.0 migrates from OpenAI Assistants to local Contao prompts plus OpenAI Responses and Conversations. Read [Upgrading from 1.x](development/troubleshooting.md#upgrading-from-1x) before updating an existing installation.
