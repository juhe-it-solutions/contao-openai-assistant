# Chat Rate Limiting And Cost Protection

The frontend chatbot endpoint (`POST /ai-chat/send`) is public and anonymous, and every accepted message spends OpenAI API credits from the site owner's API key. To keep a scripted or distributed abuser from running up an unbounded API bill, the extension enforces three independent limits. All of them return HTTP `429` with a localized error message that the chat widget displays to the visitor.

## The Three Layers

| Layer | Scope | Limit | Configurable |
|---|---|---|---|
| IP rate limit | per client IP | 10 messages per minute (default, sliding window) | Yes, backend field (`0` = off) |
| Session throttle | per visitor session | 1 message per 2 seconds | No |
| Daily cap | per OpenAI configuration, all visitors combined | 1000 replies per day (default) | Yes, backend field (`0` = off) |

1. **Per-IP rate limit.** A sliding window of messages per minute per client IP (default 10). This is the primary defense against scripted abuse: unlike the session throttle, it cannot be bypassed by discarding the session cookie, because the counter is keyed on the IP and stored in the shared application cache (`cache.app`). Requests without a resolvable client IP share one common bucket instead of bypassing the limit. 10/minute is comfortable for a real conversation and only ever throttles machine-speed senders — but it is a **shared budget when many users sit behind one egress IP**, so it is configurable (see below).

2. **Per-session throttle.** One message per 2 seconds per visitor session. Cheap first-line pacing for normal users; kept in addition to the IP limit.

3. **Per-configuration daily cap.** An absolute ceiling on how many chatbot replies one OpenAI configuration generates per day, across **all** visitors. This bounds the worst-case daily API cost even if an attacker distributes requests over many IPs. When the cap is reached, the widget shows a "daily limit reached, try again tomorrow" message and no OpenAI call is made.

The CSRF token endpoint (`GET /ai-chat/token`) additionally allows at most one token request per 10 seconds per session.

## Configuring The Limits

Both configurable limits live in the OpenAI configuration (backend → AI Tools → OpenAI Dashboard → edit configuration, next to the Vector Store ID).

**"Daily chat message limit"** — the daily cap:

- Default: **1000** replies per day.
- Set a higher value for busy sites — the field is a plain integer, so size it to your expected traffic plus headroom.
- Set **0** to disable the cap entirely (the IP limit and session throttle stay active).

Rough cost intuition: the cap times your prompt/completion size is the most the chatbot can spend on OpenAI per day, no matter what happens.

**"Chat messages per minute per IP address"** — the IP rate limit:

- Default: **10** messages per minute per client IP.
- **Corporate intranets, shared offices, NAT and proxy setups:** all users behind one egress IP share this budget collectively — ten colleagues chatting at once would throttle each other. Raise the value to match your concurrent-user expectation (e.g. 60–120), or set **0** to disable IP limiting entirely. With the IP limit off, the session throttle and the daily cap still bound abuse and cost.
- Changing the value takes effect immediately, even mid-window — no cache clearing needed.

## Operational Notes

- **Run `contao:migrate` after updating.** The limits are stored in new `tl_openai_config.chat_daily_limit` and `tl_openai_config.chat_ip_rate_limit` columns. Until the migration has added them, the daily cap is inactive and the IP limit runs at the built-in default of 10/minute; after the migration, existing configurations get the defaults (1000 / 10).
- **Behind a reverse proxy or load balancer, configure trusted proxies.** The IP limit keys on `Request::getClientIp()`. If Symfony does not trust your proxy, every visitor appears to come from the proxy's IP and shares a single per-minute budget — legitimate users would be throttled collectively. Set the `TRUSTED_PROXIES` environment variable (standard Contao/Symfony setup) so the real client IP is resolved from `X-Forwarded-For`. If you cannot configure trusted proxies, raise the per-IP field or set it to `0` as a workaround.
- **Counters live in the Symfony application cache** (`cache.app`). They survive requests and web workers, but clearing the application cache (e.g. a full `cache:clear` in some setups) resets them. That is acceptable for abuse limiting — it never blocks legitimate traffic, it only briefly forgets past abuse.
- **The limits are purely local.** They are independent of the premium add-on and the licensing server; nothing is reported anywhere. They also do not replace the rate limits OpenAI applies to your API key.

## Responses

| Condition | Status | Message key |
|---|---|---|
| IP over 10/minute | 429 | `please_wait` ("Please wait before sending another message") |
| Session faster than 2 s | 429 | `please_wait` |
| Daily cap reached | 429 | `daily_limit_reached` ("The chatbot has reached its daily message limit. Please try again tomorrow.") |

All messages are returned in English or German depending on the visitor's `Accept-Language` header, matching the other chat endpoint errors.
