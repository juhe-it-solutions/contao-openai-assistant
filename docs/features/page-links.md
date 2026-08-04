# Page Links (Premium)

The chatbot can answer with **real, working links**: to other pages of your
website, to documents such as PDFs, and to external websites.

Instead of "the price list can be found in the download area", a visitor gets

> Die aktuelle Preisliste finden Sie hier: [Preisliste 2026](#) (PDF, 1,2 MB)

This is part of the premium **automatic vector store update** and requires an
active licence.

## How it works

While Contao indexes your pages - during the automatic crawl of a synchronisation
run and while visitors browse your site - the extension collects the links each
page contains. During the next synchronisation those links are appended to the
page's knowledge document as a short, structured list:

```markdown
## Weiterführende Links auf „Preise und Konditionen"

### Dokumente und Downloads
- [Preisliste 2026](https://example.com/files/preisliste-2026.pdf) — PDF, 1,2 MB

### Seiten auf dieser Website
- [Kontaktformular](https://example.com/kontakt.html)

### Externe Links
- [ÖNORM B 1300](https://www.austrian-standards.at/...)
```

The list is built by the extension, never by the AI model. A model cannot
shorten, reword or invent a URL, and the block costs no tokens.

## Configure

In the OpenAI configuration, section **Automatic vector store update**:

| Setting | Meaning |
|---|---|
| **Collect links from the pages** | Master switch. **On by default.** |
| **Link types to include** | Pages, documents, external websites, e-mail addresses, phone numbers - individually selectable. At least one type must stay selected; to switch links off completely, use the master switch above. Configurations that existed before this feature show all types selected, which is what they already do. |
| **Exclude links** | One glob pattern per line, e.g. `*/impressum*` or `https://www.facebook.com/*`. Lines starting with `#` are notes. |
| **Add a link and document directory** | Uploads one extra document listing every page and every document of the site. Does not count towards your plan's page limit. |

> **After enabling or disabling any of these, the next synchronisation
> re-uploads every page once**, because the page documents change. Later runs are
> incremental again.

## What is filtered out - and why

Navigation, breadcrumbs, footers and similar site chrome are removed by three
independent mechanisms:

1. **Contao's own indexer markers.** Navigation, breadcrumb, pagination, search,
   login and sitemap modules are wrapped in `<!-- indexer::stop -->` by Contao,
   and that content is removed before the extension ever sees the page.
2. **Structural rules.** Links inside `<nav>`, `<header>`, `<footer>`, `<aside>`,
   `<form>`, ARIA landmarks (`role="navigation"` …), hidden elements and typical
   theme/consent classes are ignored - this catches themes that do not set the
   Contao markers.
3. **Frequency analysis.** A link that appears on a large share of all pages is
   site chrome regardless of the markup and is removed. Documents are held to a
   much higher threshold (90 % of pages), because a PDF linked from many pages -
   a price list, the terms of business - is usually genuinely important.

Beyond that, the following never appear:

- Links inside **protected (members-only)** articles or modules, and links
  **to** a protected page.
- `javascript:`, `data:` and other non-web schemes - only `http`, `https`,
  `mailto` and `tel` are ever stored.
- Credentials: a URL such as `https://user:secret@host/x` is stored without the
  credentials.
- Links to the page itself and pure in-page anchors (`#section`).
- Image lightbox links.
- Links carrying Contao's own `data-skip-search-index` attribute - the same
  marker its crawler honours, used in core on the mini calendar's month arrows
  and the article print button.
- Links marked `rel="nofollow"`, i.e. links the page itself does not endorse -
  in Contao most notably the website of a comment author.
- Calendar and archive navigation: the day cells of the calendar module and the
  month/year links of the news and event menu modules.

You can exclude anything else with the **Exclude links** patterns. Two markers
work directly in the page: `<!-- indexer::stop -->` … `<!-- indexer::continue -->`
excludes a whole region from Contao's search index *and* from link collection,
and `data-oaa-ignore-links` on a container element hides only the links inside it
while leaving the text indexed.

## Where the links open

Whether a link in an answer opens in a new tab is a separate, per-module setting -
see [Link target](link-target.md).

## Limits

- At most 40 links per page, in document order (documents first).
- The link and document directory lists at most 2000 documents and 2000 pages.
- Links are collected for every indexed page, but only pages inside your
  configured synchronisation scope end up in the vector store.

## Inspecting the result

The **downloadable document** of every synchronisation run (OpenAI dashboard →
run history) contains the full content of every uploaded page, including its link
section. The manifest header reports how many links were embedded and how many
were removed:

```
- Links embedded: 412 | removed as site chrome: 1204, removed by type/exclude rules: 63
```

## Data stored

Collected links live in the internal table `tl_openai_page_link` (one row per
document and link target). It is internal machine state with no backend screen of
its own - inspect it with a database client if you ever need to. Rows whose source
page disappears from Contao's search index are removed automatically on the next
run.

Switching the feature off leaves the collected rows in place (harmless internal
state that makes re-enabling instant); only the page documents change.
