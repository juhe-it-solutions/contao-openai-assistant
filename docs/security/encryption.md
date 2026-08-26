# Encryption

OpenAI API keys and premium license keys stored in the database are encrypted with AES-256-CBC and a random IV.

## Key Derivation

Current installations prefer a key derived from Contao/Symfony's `kernel.secret`:

```text
sha256("contao-openai-assistant:" + kernel.secret)
```

This works consistently in web and CLI contexts, which is important for migrations and cron jobs.

For backward compatibility, the service also tries legacy server-derived candidates based on host and document-root values. This keeps older encrypted values readable after the switch to the app-secret-based key.

## What Else Depends On `APP_SECRET`

Two unrelated things are derived from the same secret on a Contao installation:

- the encryption key above, protecting the stored OpenAI API key and licence key;
- Contao's signatures on download URLs (`_hash`), which the page-links feature stores verbatim and the chatbot hands to visitors — see [Page links](../features/page-links.md).

Those signatures are HMAC-SHA256 tags. They cannot be reversed, they are not vulnerable to length extension, and publishing any number of them reveals nothing about the secret — with one condition: **the secret must be the random value Contao/Symfony generated, not a hand-typed one.**

A signed URL is public by design; it sits in the HTML of the page carrying the download. Anyone holding one can test candidate secrets offline, without touching the server. Against 32 random hex characters that is hopeless. Against `changeme`, a project name or any short word it is not — and because the same secret derives the encryption key, guessing it would expose the stored API key too.

Check once per installation that `APP_SECRET` in `.env` looks generated rather than chosen. Nothing else about this is worth worrying about.

## Compatibility

The service can still process old base64-encoded API keys. New saves write encrypted values only.

## Operational Notes

- Keep `APP_SECRET` stable for an installation.
- Prefer environment variables for OpenAI API keys in production.
- If encrypted database keys cannot be decrypted after a server move, re-enter the key in the Contao backend or switch to environment variables.
- A changed `APP_SECRET` also invalidates every download link already in the knowledge base. One synchronisation replaces them; see [Page links](../features/page-links.md).