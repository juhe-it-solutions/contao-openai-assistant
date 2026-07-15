# Link Shortening (Shorten Plain URLs)

Since 2.1.0 the frontend chatbot can render plain ("naked") URLs in bot answers
as short, localized link labels instead of printing the full URL. Long URLs -
especially download links with query parameters - no longer flood the chat
bubble; visitors see a compact hint such as **Download** or **Seite aufrufen**
that is clickable and points to the complete, unmodified URL.

## Configure

Edit the frontend module of type **AI tools -> AI-Chatbot** and use the
checkbox **Shorten plain URLs** (German backend: **URLs kürzen**) in the
chat settings.

- **Enabled (default):** plain URLs are displayed as short labels.
- **Disabled:** plain URLs are displayed in full length as before (pre-2.1.0
  rendering).

The option is per module, so different chat modules on the same installation
can use different settings.

## What Gets Shortened - And What Does Not

Only URLs that the model outputs as plain text are affected:

| Model output | Rendering with option ON |
|---|---|
| `https://example.com/files/manual.pdf` | [Download] |
| `https://example.com/kontakt` | [Seite aufrufen] / [Visit page] |
| `www.example.org/broschuere.pdf` | [Download] (with `https://` prefix) |
| `<https://example.com/page>` (angle brackets) | [Seite aufrufen] / [Visit page] |
| `[Herunterladen](https://example.com/files/manual.pdf)` | [Herunterladen] - **unchanged** |
| `mailto:` / `tel:` links, e-mail addresses, phone numbers | **unchanged** |

Markdown links always keep their model-provided link text. If you want full
control over the visible labels, instruct the model to emit Markdown links (see
"Recommended system prompt" below) - the shortening only acts as a safety net
for URLs the model outputs bare.

## Label Selection

The label is chosen from the URL **path** (query string and fragment are
ignored):

- **Download label** when the path ends in a common file extension:
  `pdf, zip, rar, 7z, tar, gz, tgz, doc, docx, xls, xlsx, ppt, pptx, pps,
  ppsx, csv, txt, rtf, odt, ods, odp, epub, ics, vcf, mp3, m4a, wav, mp4,
  mov, avi, webm, jpg, jpeg, png, gif, svg, webp`
- **Page label** for everything else.

Note that a URL like `https://example.com/download-center?file=report.pdf`
gets the page label: the extension appears only in the query string, and the
link opens a page, not the file itself.

## Localization

The labels follow the visitor's browser language (`Accept-Language`), like all
other chat strings:

| Language | Download label | Page label |
|---|---|---|
| German | Download | Seite aufrufen |
| English | Download | Visit page |

The strings are defined in `contao/languages/de/mod_ai_chat.php` and
`contao/languages/en/mod_ai_chat.php` (`js_link_label_download`,
`js_link_label_page`) and are delivered to the JavaScript through the module's
`data-i18n` JSON, so they can be adjusted per installation via a Contao
language override.

## Accessibility and Link Integrity

- The **full URL is never altered**: it stays complete - including all query
  parameters - in the link's `href`.
- The full URL is also set as the link's `title`, so desktop users see it as a
  tooltip on hover.
- The link's `aria-label` contains the label plus the target hostname (for
  example "Download, example.com"), so screen-reader users know where a
  generic "Download" link leads.
- Trailing sentence punctuation stays outside the clickable link, and links
  keep `target="_blank" rel="noopener"` like all external chat links.

## Recommended System Prompt

Shortening plain URLs is a rendering fallback. For the best result, also
instruct the model (in the prompt's **Instructions** field, or in the
OpenAI-dashboard prompt if you use `prompt_id`) to emit Markdown links with
meaningful labels:

```
### Link formatting (applies to ALL links, no exceptions)

- Output every link as a standard Markdown link: [LINK TEXT](FULL_URL)
- Never output a bare/naked URL and never repeat the URL as visible text.
- The URL inside the parentheses must be complete and unmodified, including
  all query parameters (everything after ? and &). Never shorten, split,
  or line-wrap the URL.
- Choose the LINK TEXT as follows:
  - "Download" for file downloads (PDF, ZIP, PPT, DOCX, etc.)
  - "Seite aufrufen" for page links when answering in German
  - "Visit page" for page links when answering in English
  - If a more specific short label is obvious from context (e.g. the
    document title), you may use it instead - keep it under 5 words.
```

With this instruction, the model provides the labels itself (and can pick more
specific ones); the shortening option covers any URL the model still emits
bare.

## Upgrade Notes

- The option is **enabled by default**, also for existing modules: running
  `contao:migrate` adds the `tl_module.shorten_urls` column with default `1`.
  If your visitors should keep seeing full URLs, disable the checkbox in the
  module settings after the upgrade.
- No template changes are required. If a cached template without the new
  `data-shorten-urls` attribute is still served, the JavaScript defaults to
  the enabled behavior, matching the module default.

## Testing

The rendering is covered by the linkification regression harness:

```bash
node scripts/check-chat-linkification.js
```

It runs the documented linkification cases in opt-out mode (full-URL
rendering) plus dedicated cases for the shortened rendering (label selection,
href/title integrity, Markdown precedence, list deduplication safety,
i18n fallbacks).
