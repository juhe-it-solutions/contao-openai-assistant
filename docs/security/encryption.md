# Encryption

OpenAI API keys and premium license keys stored in the database are encrypted with AES-256-CBC and a random IV.

## Key Derivation

Current installations prefer a key derived from Contao/Symfony's `kernel.secret`:

```text
sha256("contao-openai-assistant:" + kernel.secret)
```

This works consistently in web and CLI contexts, which is important for migrations and cron jobs.

For backward compatibility, the service also tries legacy server-derived candidates based on host and document-root values. This keeps older encrypted values readable after the switch to the app-secret-based key.

## Compatibility

The service can still process old base64-encoded API keys. New saves write encrypted values only.

## Operational Notes

- Keep `APP_SECRET` stable for an installation.
- Prefer environment variables for OpenAI API keys in production.
- If encrypted database keys cannot be decrypted after a server move, re-enter the key in the Contao backend or switch to environment variables.