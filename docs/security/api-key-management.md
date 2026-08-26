# API Key Management

The extension resolves OpenAI API keys in this order:

1. `OPENAI_API_KEY_{configId}`
2. `OPENAI_API_KEY`
3. encrypted value stored in `tl_openai_config.api_key`

Environment variables are recommended for production because the key can be rotated without changing the database.

## Environment Variable Example

```bash
OPENAI_API_KEY_1=sk-proj-...
```

The numeric suffix is the ID of the OpenAI configuration record in Contao. You can find it in the backend edit URL or database row.

## Database Storage

If no environment variable is available, the API key entered in the backend is validated and stored encrypted. Existing legacy base64 values are still read for compatibility.

## Supported Key Prefixes

The current validator accepts OpenAI key prefixes used by regular, project and service-account keys, including `sk-`, `sk-proj-` and `sk-svcacct-`.

## Operational Notes

- Never commit `.env`, `.env.local` or copied keys.
- Use different keys for development, staging and production.
- Rotate keys in OpenAI if access may have leaked.
- After changing environment variables, clear the Contao cache or restart the PHP process if your hosting requires it.