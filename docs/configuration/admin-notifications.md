# Chatbot failure reporting

When the chatbot cannot answer a visitor, the visitor only sees "Service temporarily unavailable". The extension reports these failures to the administrators in two ways:

- **System log entries** (always on): every failure appears in the Contao backend under **System > System log**.
- **Email notifications** (optional, off by default): one or more administrator addresses get an email when the chatbot fails or the daily message limit is reached.

Both are part of the free (LGPL) extension.

## What is reported

A **failure** is any chat message the chatbot could not answer:

- OpenAI rejected the request: no credit or quota left, rate limit, invalid or revoked API key, unknown model or invalid prompt setting
- OpenAI's servers returned an error or were unavailable
- the connection from the server to OpenAI failed or timed out
- OpenAI returned an incomplete or empty answer
- the chatbot is not fully configured (no prompt, no valid API key, no OpenAI configuration)
- an unexpected internal error

Reaching the **daily chat message limit** is reported as well. It is not a failure, but visitors get no answers until the limit resets.

Not reported, because they are intended protections and not failures: the per-IP rate limit, the 2-second session throttle, the message length limit, missing or invalid request tokens, empty messages and non-AJAX requests. Errors outside the visitor's chat message (loading the chat history, the vector store sync, cron jobs) are not reported either.

## System log entries

Every failure writes one entry to **System > System log**, for example:

```
Chatbot could not answer a visitor on "Main site" (example.com): quota_exceeded, HTTP 429, OpenAI error code insufficient_quota. Details are in var/logs.
```

- The entry names the website (see below), the reason, and when known the HTTP status and OpenAI's error code. The reason values are fixed and searchable: `quota_exceeded`, `rate_limited`, `authentication_failed`, `request_rejected`, `openai_unavailable`, `connection_failed`, `incomplete_response`, `not_configured`, `unknown`.
- Reaching the daily limit is logged once per configuration and day.
- At most **100 entries per configuration and hour**. The 101st failure writes one "more than 100 entries this hour" entry; further failures in that hour are only written to the application log (`var/logs`), which keeps every failure with full technical details as before.
- The entries contain **no visitor data**: no message text, no IP address, no user agent (the "Browser" column shows `N/A`), no username of a logged-in member (`N/A`), and only the path `/ai-chat/send` as URI, never a query string.
- Contao deletes system log entries after the log period set under **System > Settings** (default: 7 days).

There is no setting for the system log entries; they are always written.

## Email notifications

### What triggers an email

- **Failure email:** a visitor's chat message failed, for any of the reasons above.
- **Daily-limit email:** the configured daily chat message limit was reached.

The email is short plain text in German or English. It names the affected website, the reason in plain words, what to do, the time and the OpenAI configuration. The daily-limit email also says when the limit resets.

### How often

- At most **one failure email per hour** per configuration, whatever the reason.
- At most **one daily-limit email per day** per configuration. The day is the budget day of the daily limit, which resets at midnight UTC.
- The two are independent: a failure email and a daily-limit email can arrive in the same hour.
- During a long outage you get up to one email per hour. Every single failure is still listed in the system log.
- The throttle is kept in the application cache. Clearing the cache (a deployment, the Contao Manager) resets it, so in the worst case one extra email is sent. On installations with several web servers that do not share a cache, each server throttles separately.

### Setup

1. Set the administrator email address under **System > Settings**. It is used as the sender. Recipient addresses are never used as the sender.
2. Make sure the installation can send email (the same mail settings Contao uses for forms and password resets).
3. Open **AI Tools > OpenAI Dashboard** and edit the configuration.
4. In the section **Administrator notifications**, tick **Email on chatbot failures**. The form reloads.
5. Enter one to ten **recipient email addresses**, one per row. Use the row buttons to add or remove rows.
6. Choose the **email language**. It defaults to the language of your backend user.
7. **Save** the configuration.
8. Click **Send test email**. It uses the saved settings and confirms that the mail reaches every recipient. Check the spam folder too. You can send one test email every 30 seconds.

Notifications are **off by default**. There is no fallback recipient: until you enter addresses here, nobody receives anything. After updating the extension, run the database update (`contao:migrate` or the Contao Manager) so the three new configuration fields exist; until then, notifications count as switched off.

### How the website is identified

The email names the website by the **page name** of its website root (the "Page name" / "Seitenname" field of the root page), followed by the domain:

- **Website root with a domain:** the page name and the root's domain, for example `"Main site" (https://example.com)`.
- **Website root without a domain:** the page name and the domain the visitor used, marked **unverified** (for example `example.com (unverified: no domain is set in this website root)`). Set the domain in the website root to have it verified.
- **No matching website root:** the domain is marked unverified, and the email lists the page names of all published website roots of the installation instead.

"Unverified" means the domain comes from the request and could not be checked against the website root. In normal operation it is the real domain; the marking only matters for a deliberately forged request.

### Delivery

Emails go through the installation's existing mail transport. The extension adds no new mail service, queue or cron job, and sends nothing to OpenAI or to JUHE IT-solutions.

The email is sent after the visitor's response has been delivered. On PHP-FPM a slow or unreachable mail server therefore does not delay the visitor at all. If sending fails, or if no valid recipient or sender address is set, the system log shows an entry such as "Chatbot notification email could not be sent" (at most once per throttle window).

## Data protection

- **Visitors:** neither the email nor the system log entry contains visitor data: no message text, no part of the chatbot's answer, no IP address, user agent, language header, cookies, session or request token, no OpenAI conversation or response ID, no referer or page URL, and not OpenAI's error message text (it can quote the visitor's input). Only the HTTP status and OpenAI's machine-readable error code (for example `insufficient_quota`) are used.
- **What the email contains:** the website's page name and ID, the domain, (only when no website root matches) the page names of all published website roots, the OpenAI configuration title and ID, the time, a fixed reason text and, when available, the HTTP status and OpenAI error code.
- **Administrators:** the recipient addresses are stored in the OpenAI configuration and used only for these emails. Like every versioned field, earlier values also remain in the record's version history (`tl_version`) until Contao purges old versions.
- **Cache:** the throttle stores only a timestamp or a counter per configuration, no addresses or other personal data.

This is a technical description, not legal advice.
