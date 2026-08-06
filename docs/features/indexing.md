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
synchronisation aggregates by page id. They become **one** vector-store document
citing a single item URL, and consume **one** page of the plan quota rather than
one per item.

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

## Whole-website scope

With no explicit page selection the synchronisation covers the whole website,
but only when exactly one **live** site root carries a domain name. "Live"
follows the same publish predicate as above, so unpublished theme roots left
behind by an import no longer count. More than one live root requires an
explicit page selection.
