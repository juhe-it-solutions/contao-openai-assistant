# Upgrading to 2.2.0

For installations coming from **2.1.4 or earlier**. Read this before you update a
production site; the [CHANGELOG](../CHANGELOG.md) has the full detail of every
individual change.

Nothing here is a fault to report. 2.2.0 indexes more of your website, more
correctly, than 2.1.4 did - and doing that once costs time and, in
**"KI-optimiert"** mode, tokens.

---

## The two things that matter most

### 1. Run `contao:migrate` immediately after deploying the code

This release adds columns and two tables. Until the migration has run:

- **KI-Tools → "OpenAI Vector-Store-Auto-Update"** shows a notice telling you exactly
  this, instead of the dashboard.
- Scheduled synchronisations skip, with a note in the system log.
- **"Jetzt manuell synchronisieren"** answers with the same notice.
- Saving the OpenAI configuration or a chat module fails with a database error.
  This one is unavoidable - Contao writes every field of a form, so any extension
  that adds fields behaves this way.

Your chatbot keeps answering visitors throughout, and nothing in your vector
store is touched. Run the migration through the Contao install tool or:

```bash
vendor/bin/contao-console contao:migrate
```

**Apply the whole database update, not a selection of it.** The Contao Manager
lets you tick individual statements; a partial apply is detected and refused
rather than half-run, but it leaves the synchronisation paused until you finish.

### 2. The first synchronisation after the update rebuilds everything

Five changes in this release each alter every page document, so the first run
re-uploads your whole site. Together, plan for **one long, expensive run**, then
cheap incremental runs again:

| What changed | Why every page is re-uploaded |
|---|---|
| Link collection is **on by default** | Each page document gains a "Weiterführende Links" section |
| A link and document directory is added | One extra site-wide document |
| Reader pages are renamed | News/FAQ/event pages take their title and URL from the page itself |
| The rewrite cache starts empty | In "KI-optimiert" mode every page is rewritten once |
| The default rewrite prompt changed | Installations with a **custom** prompt are unaffected |

On top of that, **the crawl no longer has a depth limit**. Contao's default
stopped three clicks from the home page, so page 2 of a news list - and
everything behind it - was never indexed. Those pages now enter the knowledge
base for the first time. This is the fix that makes the chatbot complete, and it
is also why the first crawl takes noticeably longer than any run you have seen
before.

**What to do:** start the first synchronisation **manually** and watch it, rather
than letting the nightly cron discover all of this unattended. On a large
calendar or a deep page tree the crawl can run for a long time; if your hosting
kills long-running processes, that is the run where you will find out.

Working the other way, **later runs get cheaper than they were before 2.2.0**.
The new setting *"Suchindex vor der Synchronisierung aktualisieren"* defaults to
**Automatisch**, which skips the crawl entirely while the website is unchanged
and forces one at least every six hours. That crawl always covered the whole
site - all 2000 pages even when only 30 are selected for the chatbot - so on a
frequent schedule this is where most of the cost was. The first run after the
update still crawls: it has no previous state to compare against. Nothing about
which content reaches the chatbot changes; set the field to **"Bei jedem Lauf"**
if you want the old behaviour back.

If you would rather not have the link feature yet, switch **"Links aus den Seiteninhalten übernehmen"**
(and the link directory) off in the OpenAI configuration *before* the first
synchronisation. The other four causes still apply, so this reduces the cost - it
does not remove it.

---

## Before you update

1. **Back up the database.** Note your current vector-store file count and the
   date of the last successful synchronisation, so you can compare afterwards.
2. **Delete surplus OpenAI configurations.** If an older version left more than
   one row in the configuration list, the chatbot now uses the **first** one
   (lowest ID) instead of the most recently saved one. On a multi-configuration
   install this can silently change which API key, vector store and prompt answer
   your visitors. Keep exactly the one you maintain; the system log warns when
   more than one exists.
3. **Check whether you use custom chat templates or JavaScript.** Stock templates
   need nothing. A copied `ai_chat` template or custom JS from 2.1.4 needs
   updating: reading the chat history now requires the security token, the
   endpoint addresses come from `data-*-endpoint` attributes on the element, and
   the assets load through the `contao_openai_assistant` package. Without those
   changes the widget can come up empty or answer 403 - particularly on
   installations served from a subdirectory.

## Updating

4. Update the code (Contao Manager or `composer update`).
5. **Run `contao:migrate`.**
6. Purge the Contao and page caches.
7. Grant the new back-end module **"OpenAI Vector-Store-Dateien"**
   (`openai_vector_file`) to any non-admin user group that should see which
   OpenAI file holds which page. Admins have it already; Contao does not hand a
   new module to existing groups automatically.
8. Open the OpenAI configuration and decide on **"Links aus den Seiteninhalten übernehmen"** and the link
   directory (see above).
9. Start one synchronisation **manually** and watch it through.

## Afterwards

10. Smoke-test: send a chat message, reload and check the transcript, use the
    **"Schlüssel prüfen"** and licence check buttons, download a run manifest,
    and open the new file list.

---

## Expected after the update - not faults

**A conversation in progress shows an empty transcript once.** The chatbot still
knows the conversation; only the displayed history starts empty, and it fills
again from the visitor's next message.

**Runs report "partial" every night on news-heavy sites.** News, FAQ and event
entries now have their own subscription allowance, separate from the page limit:
**Einsteiger 50 entries, Business 300, Enterprise unlimited**. Because the
unlimited-depth crawl finally reaches all of them, a site that always reported
"success" can now report **"partial"** with a notice asking you to upgrade.

**Nothing is removed and nothing stops syncing when that happens.** Everything is
still uploaded. "Partial" here means "please look at this", not "this failed".

**Member-only pages disappear from the vector store** - but only if you had
enabled Contao's `contao.search.index_protected` setting, which is **off by
default**. With it on, 2.1.4 uploaded protected page content into a knowledge
base that answers anonymous visitors. Those pages are now excluded and removed on
the next run, counted under "removed" in the manifest. If you never enabled that
setting, this does not affect you at all.

**Which pages survive the plan page limit can shift.** When the selected scope
exceeds your plan's page allowance (**Einsteiger 20, Business 50**), the pages
with the lowest IDs are kept. Newly reachable deep pages take part in that
decision now, so the composition of the store can change without you changing the
selection.

**The daily chat message limit restarts once, on the day you update.** A fix to
the counter changes its key, so messages sent earlier that day no longer count
against it. One day only.

**A very long page can be re-rewritten every run.** If the AI model cuts off a
rewrite at its output limit, the result is discarded (a truncated document would
otherwise be cached permanently) and the page's unmodified text is used. Correct,
but it means that page pays tokens each time. Shorten it, or use the faithful
indexing mode.

---

## If something looks wrong

- **The dashboard shows a database notice** → the migration has not run. See
  step 5.
- **"Jetzt manuell synchronisieren" is greyed out** → open the warnings on the
  configuration card. Without an explicit page selection, the synchronisation
  needs exactly **one live site root**: published, and inside its start/stop
  dates if set. A leftover site root from a theme import is the usual cause.
- **The chat widget is silent on a subdirectory installation** → custom template
  or JavaScript, see step 3.
- **The run history shows an error mentioning the cache** → a deployment or cache
  rebuild interrupted the crawl. Nothing was changed in the vector store; start
  the synchronisation again.

Further reading: [What gets indexed](features/indexing.md) ·
[Page links](features/page-links.md) ·
[Troubleshooting](development/troubleshooting.md) ·
[premium help pages](https://licenses.juhe-it-solutions.at/en/openai-assistant/help)
