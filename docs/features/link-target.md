# Link Target (Where Chat Links Open)

Links in chatbot answers used to always open in a new browser tab. The frontend
module now has an **Open links in** setting that controls this per module - most
importantly it can keep links to your *own* website in the current tab, while
still opening foreign websites in a new one.

This matters as soon as the chatbot suggests pages of your own site (for
example when the vector store contains the links found on your pages): a
visitor who is guided to "Kontaktformular" usually expects to navigate there,
not to collect a new browser tab for every suggestion.

## Configure

Edit the frontend module of type **AI tools -> AI-Chatbot** and use the select
**Open links in** (German backend: **Links öffnen in**) in the chat settings.

| Option | Behaviour |
|---|---|
| **New tab (all links)** | Every link opens in a new tab (`target="_blank" rel="noopener"`). This is the default and matches the behaviour of all previous versions. |
| **New tab for external links and documents** | Links to **pages** of your own website stay in the current tab. External links **and documents** open in a new tab. |
| **Same tab (all links)** | No link opens a new tab - documents included. |

### Why documents are treated like external links

In the middle option, a document on your own website (`/files/preisliste.pdf`)
still opens in a new tab. A PDF that the browser renders inline *replaces* the
page - and the visible chat transcript exists only in the page (nothing but the
colour theme is stored in the browser), so a download in the current tab would
drop the conversation the visitor was having. Own **pages** are different:
navigating there is exactly what the visitor asked for.

A link is treated as a document when its **path** ends in a common file
extension (`pdf, zip, docx, xlsx, pptx, csv, odt, mp4, jpg …` - the same list
the [link shortening](link-shortening.md) feature uses for its *Download*
label). A URL such as `https://example.com/download-center?file=report.pdf` is
a page, not a document: the extension only appears in the query string. That is
also harmless - Contao serves those download URLs with
`Content-Disposition: attachment`, so the browser downloads the file without
navigating away at all, no matter which target the link carries.

If you pick **Same tab (all links)** the rule is taken literally and documents
stay in the tab as well - an explicit choice is honoured.

The option is per module, so different chat modules on the same installation
can use different settings.

Existing modules keep the previous behaviour after an update: the database
column defaults to `blank`, and a chat template that predates the option (a
custom `customTpl` copy without `data-link-target`) also falls back to
"New tab (all links)".

## What Counts As "Own Website"

The comparison uses the **host** of the page the chatbot is embedded in:

- `https://example.com/kontakt.html` on `example.com` → same site
- `https://www.example.com/kontakt.html` on `example.com` → same site
  (a leading `www.` is ignored on both sides, because both addresses serve the
  same website to every visitor)
- `https://shop.example.com/x` on `example.com` → **external** (a different
  host, even though the domain matches)
- `https://other.tld/x` → **external**
- Anything unparseable → treated as external, i.e. it keeps the new tab

In a multi-domain Contao installation, a link to another root domain of the
same installation counts as external. That is intentional: "same tab" means
staying on the site the visitor is currently browsing.

## What Is Not Affected

- **`mailto:` and `tel:` links, e-mail addresses and phone numbers.** These
  never carried a target attribute and never open a tab - the browser hands
  them to the mail or phone application.
- **The link text.** Whether a label is shortened is controlled by the separate
  option [Shorten plain URLs](link-shortening.md); the two settings are
  independent.
- **The `href`.** The destination is never rewritten by this option.

## Accessibility Note

Opening links in a new tab without warning the user is an advisory failure of
WCAG technique G201. The chatbot mitigates this by exposing the destination in
the `title` attribute (and, for shortened labels, in `aria-label`), but if
accessibility conformance matters to you, prefer **New tab for external links
only** or **Same tab (all links)**.

## Verification

The rendering is covered by the regression harness:

```bash
node scripts/check-chat-linkification.js
```

The `target-*` cases assert all three modes, including the `www.` handling and
the fact that `mailto:`/`tel:` anchors stay untouched.
