# What Gets Indexed (Premium)

Technical reference for which content reaches the vector store, and why content
sometimes does not. The customer-facing version of this lives in the
[premium add-on help pages](https://licenses.juhe-it-solutions.at/en/openai-assistant/help);
this document records the mechanics and cites the Contao core code they rest on.

The synchronisation never reads your pages directly. It reads **Contao's search
index** (`tl_search`), which it refreshes at the start of every run by spawning
`contao:crawl --subscribers=search-index`. So the real question is always: what
does Contao put into its search index?

## Two independent indexing paths

Contao fills `tl_search` in two ways, and they behave differently:

| Path | Trigger | Notes |
| --- | --- | --- |
| `SearchIndexListener` | A real front-end request, on `kernel.terminate` | Explicitly skips the crawler's own user agent |
| `SearchIndexSubscriber` | `contao:crawl` | The path the synchronisation uses |

A handful of rows appearing without a crawl usually means visitors browsed those
pages. Always re-crawl before drawing conclusions from `tl_search`.

## Requirements for a page

All four must hold:

1. **Published**, including the publication window. Contao's own predicate
   (`PageModel::findPublishedById()`) is
   `published = '1' AND (start = '' OR start <= $t) AND (stop = '' OR stop > $t)`
   with `$t` floored to the minute. Note `stop` is compared strictly greater.
2. **Indexable** — not `noindex`, not `noSearch`. A `noindex` response is not
   merely skipped: `SearchIndexListener::needsDelete()` **deletes** any existing
   row.
3. **Not protected.** The synchronisation excludes protected rows
   unconditionally, because the chat endpoint is anonymous and has no equivalent
   of Contao's member-group filtering at query time.
4. **Reachable by link.** The crawler seeds only from root page URLs
   (`Crawl/Escargot/Factory.php`, `getRootPageUriCollection()`) and discovers
   everything else by following links. It does **not** read `sitemap.xml`, so
   `tl_page.sitemap` has no effect on indexing.

### `nofollow` blocks discovery, not just ranking

A URL found on a page whose response carries `nofollow` is **never requested**
(`SearchIndexSubscriberTest`: *"Do not request because the URI was disallowed to
be followed by nofollow or robots.txt hints."*). Because the page is never
fetched, its own robots value never gets a chance to apply.

Every page on the path from the site root to the content must therefore be
followable.

## News, FAQs and events

These share one architecture: many items are rendered by a single **reader
page**, differentiated by an `auto_item` URL segment.

### Effective robots

> the **item's** `robots` if it is non-empty, otherwise the **reader page's**

The reader modules only override when the item sets a value
(`ModuleNewsReader`, `ModuleFaqReader`, `ModuleEventReader` all guard with
`if ($obj->robots)`). Since `tl_news.robots` and friends are empty by default,
**the reader page's setting governs in the common case**.

Set the reader page to `index,follow`. Relying on per-item values is fragile:
one item saved with an empty robots field silently drops out.

### Practical checklist

- Reader page: `index,follow`, `noSearch` off, not protected, published.
- The page holding the **list** module must be indexable too — its links are how
  items get discovered.
- The list must link to *every* item. A fixed `numberOfItems` with no pagination
  makes the remainder permanently invisible to the crawler.
- `requireItem` is optional. It keeps the bare reader-page URL out of the store
  by making it 404. Never enable it on a page that also carries the list — the
  list view itself would 404.
- Items whose `source` is `internal`, `article` or `external` 301-redirect and
  never produce a reader URL at all; the target page is what gets indexed.

### One merged document

All items behind a reader page share that page's `tl_page` id, and the
synchronisation aggregates by page id. They become **one** vector-store document.

That document is named after the **page** (`tl_page.title`, what you see in the
site structure) and cites the page's own URL — not the `<title>` and URL of one
entry. The indexed `<title>` of a single row would name an arbitrary entry, and
would keep naming it after that entry is deleted, because the row in `tl_search`
outlives it. The page's URL is identified as the shortest of its indexed URLs: an
entry only ever adds a path segment or a query parameter to it. Only when the page
row itself is gone does the indexed title remain as a fallback.

A page whose title or URL changes this way is re-uploaded on the next run even if
its text is unchanged — the title heads the uploaded document and travels as a
file attribute, so the store would otherwise keep the old heading.

## How the plan limits are counted

The subscription has **two independent budgets**:

| Plan | Pages | News/FAQ/event items |
| --- | --- | --- |
| Starter | 20 | 50 |
| Business | 50 | 300 |
| Enterprise | unlimited | unlimited |

They are budgeted separately rather than added up because they behave
differently. A page is added deliberately and rarely; items accumulate on their
own as an editor keeps publishing. Charging both against one budget would mean a
customer's page selection silently runs out of room because somebody wrote a
news post.

Counting `tl_page` rows alone was the loophole: an installation consisting of a
single published page carrying a news reader module with hundreds of items
counted as **one** page and stayed inside the smallest plan while putting the
content of hundreds of items into the knowledge base.

Note what an item is and is not. Each item **is** its own document in Contao's
search index (its own URL, its own `tl_search` row), but it does **not** become
its own file in the vector store — see "One merged document" above. The page
budget therefore meters files; the item budget meters content volume. Copy aimed
at customers must not imply the two work the same way.

### Counted from what is actually indexed

The raw number of published items would over-charge: a list module showing 5 of
300 news items without pagination means 295 of them are never crawled and never
reach the chatbot. The count is therefore capped at the number of URLs the page
actually has in `tl_search`:

```
items(page) = min(published items in the database, indexed URLs of that page)
```

A consequence worth knowing: before the first crawl nothing is indexed, so the
item count reads 0. That is honest — nothing is in the knowledge base yet — and
the number that matters commercially is the one computed during a run, where the
crawl has just refreshed the index.

`ReaderItemCounter` derives the item count from the three content bundles:

| Reader page found via | Items counted from |
| --- | --- |
| `tl_news_archive.jumpTo` | `tl_news` |
| `tl_faq_category.jumpTo` | `tl_faq` |
| `tl_calendar.jumpTo` | `tl_calendar_events` |

Only items that really become a document are counted — published including the
start/stop window, and not redirecting elsewhere via `source`. All three bundles
are optional, so tables and columns are schema-guarded (`tl_faq` has neither a
start/stop window nor a `source`).

Page and item limits resolve through `LicenseValidationService::resolvePageLimit()`
and `resolveItemLimit()`. The page limit can be overridden per license by the
licensing server (`max_crawl_pages`); the item limit is derived from the plan
name alone, so a per-license override would need a new field on both sides.

Enforcement differs between the two, deliberately:

- **On save** (`enforceCrawlPageLimit()`) a selection over the **page** budget is
  rejected and the previous selection is kept. An **item** overflow only adds a
  `Message::addInfo()` notice — the callback runs on every save of the
  configuration, not only when the selection changes, so throwing would lock the
  customer out of their API key, prompt and schedule the moment an editor
  published one item too many. Unlike pages there is usually no selection left to
  reduce either: the reader page *is* the content.
- **During a run**, pages beyond the page limit are dropped
  (`applyPlanPageLimit()`, deterministic by page id) and the run is reported
  `partial`. When a dropped page carried reader items, the message names them
  (`MSC.vsau_plan_limit_truncated_items`) — "1 page was not synced" badly
  understates the loss when that page held three hundred news entries.
- **During a run, an item overage never removes anything.**
  All items behind a reader page end up in **one**
  document, so there is no way to leave out just the excess — dropping the page
  would take the items that are within the limit with it and strip the chatbot
  of the whole news section at once. Publishing one item too many must not have
  that effect, so everything is synced and the run is flagged `partial` with a
  message asking for an upgrade.

## Unpublishing does not remove indexed content

Core purges the search index on **delete**, **alias change** and **robots
change** (`NewsSearchListener`, `FaqSearchListener`, `EventSearchListener`).
There is no callback for `published`, `start` or `stop`.

So when an item is unpublished or its stop date passes:

- the list stops linking it, so the crawler never revisits the URL,
- the URL is therefore never re-evaluated and never returns its 404,
- and the existing `tl_search` row survives — feeding withdrawn text into the
  next synchronisation.

**Cleanup:** purge the search index (System → Maintenance), then run a full
synchronisation. A plain re-crawl does not fix it, because the URL is no longer
reachable to be re-checked. Deleting an item outright is handled automatically.

The synchronisation cannot detect this on its own: distinguishing a stale item
row from a live one would mean re-requesting every indexed URL, which defeats
the purpose of reading the index.

## Verifying

```sql
SELECT
  (SELECT COUNT(*) FROM tl_search s
     INNER JOIN tl_page p ON p.id = s.pid
   WHERE p.alias = '<reader-page-alias>') AS indexed_rows,
  (SELECT COUNT(*) FROM tl_news n
     INNER JOIN tl_news_archive a ON a.id = n.pid
     INNER JOIN tl_page p ON p.id = a.jumpTo
   WHERE p.alias = '<reader-page-alias>' AND n.published = '1') AS published_items;
```

`indexed_rows` should match `published_items`, plus one if the bare reader page
is indexed too. A shortfall points at discovery (linking, `nofollow`, list
limits) rather than at robots settings.

## Which OpenAI file holds which page

Every page is uploaded as its own vector-store file, so a site with 21 pages ends
up with 21 files - while a single run only uploads the pages that changed. The
OpenAI platform lists those files by id, which by itself says nothing about the
page behind it. Two places close that gap:

- **Backend → AI Tools → "OpenAI Vector-Store-Dateien"** (`tl_openai_vector_file`)
  is the live map: page id, title, URL, indexed URLs, part, size, OpenAI file id
  and status, searchable by file id. The page id links into the site structure,
  the URL opens the live page, and "Inhalt anzeigen" shows the indexed text of
  that page. The auto-sync dashboard links straight to the list of one
  configuration via "Indexierte Dateien anzeigen".

  The list covers **only** the files the synchronisation manages. Files uploaded
  by hand (OpenAI Dashboard → File upload) are not part of it and are never
  touched by a sync.

  **Indexed URLs** is the answer to "where did my news entries go": an ordinary
  page has one, a reader page has one per indexed entry, and all of them merge
  into that page's single document (see "One merged document"). The number is
  read live from `tl_search`, so it reflects the current index rather than the
  moment of the last run.

  **Inhalt anzeigen** serves the page's block out of the newest stored run
  manifest — OpenAI refuses to return the content of `purpose=assistants` files,
  so the local copy is the only source. Pages that the manifest had to drop at
  its 8 MB cap have no block and report that instead.
- **The downloadable run manifest** names the file(s) of every page in the run,
  together with what happened to the page (`added`, `updated`, `unchanged`,
  `failed`):

  ```
  ## Preise
  URL: https://example.com/preise
  Page ID: 7 | Status: added
  Vector store file: file-abc123
  ```

Uploads also carry a speaking file name (`seite-7-preise.md`, and
`seite-7-preise-teil-1-von-2.md` for a page split across several files), so the
OpenAI file list is readable without a lookup. Files uploaded by an earlier
version keep their previous random name until the page changes and is re-uploaded.

The list is machine state: rows are created and removed by the synchronisation
itself and cannot be edited or deleted by hand. It therefore never grows past the
number of indexed pages — a page that leaves the scope takes its row (and its
remote file) with it on the next run. Deleting a row by hand would make the next
run upload the page a second time and leave the first file orphaned in the store,
which is why the table has no delete operation.

## Whole-website scope

With no explicit page selection the synchronisation covers the whole website,
but only when exactly one **live** site root carries a domain name. "Live"
follows the same publish predicate as above, so unpublished theme roots left
behind by an import no longer count. More than one live root requires an
explicit page selection.
