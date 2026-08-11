# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - 3.0.0

> **This is the Contao 6 line.** It requires **Contao 6.0 and PHP 8.4** and does not install on
> Contao 5. Everything below is also in the 2.x line, which carries the same features for
> Contao 5.3 and 5.7 - the only difference between the two lines is the platform.
>
> **Read [Upgrading to 3.0.0](docs/upgrading-to-3.0.0.md) first.** It covers the order the two
> upgrades have to happen in: Contao 5 to Contao 6 is the Contao project's own migration, and
> this extension follows it rather than driving it.
>
> **Coming from 2.1.4 or earlier as well?** Then [Upgrading to 2.2.0](docs/upgrading-to-2.2.0.md)
> applies on top: run `contao:migrate` immediately after deploying the code, and expect the
> first synchronisation to rebuild the whole knowledge base once - a long crawl and, in
> "KI-optimiert" mode, a one-off token bill. Later runs are incremental again.

### Changed

- **Requires Contao 6.0 and PHP 8.4.** The previous line required Contao 5.3 and PHP 8.2. This
  is the only breaking change in 3.0.0: no feature was removed, no setting was renamed, and the
  database schema is the same as 2.2.0, so an installation moving from 2.2.0 on Contao 6 has
  nothing to do beyond the usual `contao:migrate`.
- **Adapted to the Contao 6 template and backend APIs.** No `.html5` templates are shipped any
  more; rich text passes through Contao 6's own `sanitize_html`, CSP and insert-tag pipeline;
  the removed `child_record_callback` is replaced by label callbacks returning `RecordLabel`
  with database values escaped; the backend icon path is resolved through `Image::getPath()`
  instead of being hard-coded; and the backend JavaScript re-runs on Turbo navigations, which
  Contao 6 uses for backend page changes.

### Added
- **A run that changed nothing now says so instead of looking broken (premium).** With the crawl skipped on an unchanged website, a synchronisation finishes in seconds and leaves a row in **"Letzte 10 Synchronisierungen"** and in the **OpenAI Sync-Protokoll** that reads: no message, no files, duration 00:00 - indistinguishable at a glance from a run that fell over. Such a run now carries the note **"Keine Änderung von Seiteninhalten — nichts übertragen"** in the *Meldung* column of both tables, and the downloadable run document states that the search index was not rebuilt because the website was unchanged. The run stays green: the note is informational and is deliberately kept apart from the messages that mark a run as "teilweise", so a healthy no-op is never reported as a problem. A run that only **removed** a page is not counted as unchanged - it uploaded no file, but it did change the knowledge base, and that is the one change nobody sees by looking at their website.
- **A synchronisation no longer calls up your whole website when nothing has changed (premium).** Before reading your pages, every run had Contao crawl the site to refresh its search index - and that crawl always covers the **entire** website, not just the pages selected for the chatbot, because Contao finds pages by following links and cannot be told to visit a subset. On a site of 2000 pages with 30 selected, that is 2000 page calls per run; on an hourly schedule, 48000 a day, most of them producing nothing at all. The new setting **"Suchindex vor der Synchronisierung aktualisieren"** defaults to **Automatisch**: the crawl is skipped while the website is demonstrably unchanged, and forced anyway at least every six hours. The change check looks at everything that can reach the search index - pages, content elements, news, FAQs and events - and counts records as well as timestamps, so a **deleted** page is noticed too, which no timestamp would reveal. The six-hour ceiling is the safety net for changes nothing records at all: an item whose publication period starts or ends, or content pulled in from elsewhere. Two other choices are available: **Bei jedem Lauf** restores the previous behaviour, and **Nie** is for installations that refresh the search index themselves with their own `contao:crawl` cron job. Requires `contao:migrate` for two new columns. **What it means in practice:** a daily schedule is unaffected; a frequent schedule on a quiet site becomes nearly free; and nothing about which content ends up in the chatbot changes.
- **The default AI rewrite prompt protects links and individual entries (premium).** Testing on a live site showed the model rewriting web addresses it was asked to reproduce, and building its own list of links in which a document title ended up pointing at a different file - beside the verified list the extension adds itself. The default prompt now requires URLs to be copied character for character, forbids attaching an address to an item it did not belong to, and forbids collecting links into a list at all, since a complete and verified one is appended automatically. It also requires every separate entry on a page (news article, FAQ entry, event) to keep its own section, so none is merged away as a repetition. **Note:** this changes the instructions the model works from, so the first synchronisation after the update rewrites and re-uploads every page once in "KI-optimiert" mode. Installations with a customised prompt keep theirs and are unaffected.
- **"KI-optimiert" no longer pays to rewrite pages that have not changed (premium).** In that indexing mode every page was sent to the AI model on every synchronisation - on an hourly schedule, that is the same bill for producing the same document again and again. Worse, the decision whether to re-upload a page compares the MODEL's output, so two rewrites of an untouched page differing by a single character re-uploaded the whole site. Rewritten documents are now cached and reused as long as nothing that could change them has changed: the page text itself, the selected model, and the system prompt. Editing a page, switching the model or adjusting the prompt still re-writes it; a quiet site costs nothing after the first run. The cache is keyed on the exact text handed to the model, so it also notices the subtler case where a page's own text is untouched but a change elsewhere in the selection altered what the boilerplate filter strips from it. Requires `contao:migrate` for the new table `tl_openai_polish_cache`.
- **A rewrite cut off by the AI model's output limit is no longer used (premium).** A long page - typically a news, FAQ or event page carrying many entries - can produce a rewrite that the model stops mid-sentence. That truncated document was accepted as if complete and uploaded to the knowledge base. It is now detected, reported in the system log, and the page's unmodified text is used instead, which is complete by construction. Truncated rewrites are also never cached, so the loss cannot become permanent.
- **The synchronisation dashboard now shows a progress bar, a start time and how long a run took (premium).** The box **"Letzte Synchronisierung"** previously showed a single timestamp that changed meaning depending on what was happening: while a run was in flight it was the internal heartbeat, rewritten every minute, so it read as "just now" throughout the run and only became the finish time afterwards - and how long the run had been going, or had taken, was nowhere to be seen. The box now states **when the current run started** ("Gestartet: 09.08.2026 17:12:04"), or when it was queued, and appends the **duration** to the finish time of every completed run ("09.08.2026 17:24:40 (Dauer 12 Min. 36 Sek.)") - including runs that ended in an error, where how long it survived is often the most telling detail. A **progress bar** sits under the phase description and updates live every five seconds without reloading the page. It shows a real percentage while pages are being prepared by the AI and while they are being uploaded to the vector store, because both count pages against a set that is known in advance. The crawl cannot be measured that way and does not pretend to be: it shows a moving band with no percentage, above a line that names what it can honestly report - "Website wird gecrawlt und indexiert … 3 Seite(n) neu indexiert", the pages Contao has actually written to its search index so far. On a website nobody has edited since the last synchronisation that number stays at zero for the whole crawl, and that is correct: Contao skips a page whose content has not changed instead of indexing it again. The bar appears only while a run is queued or in progress; a finished run is described by its result and duration instead. Requires `contao:migrate` for the new column.
- **The chatbot can now answer with real links (premium).** While Contao indexes your pages, the extension collects the links they contain - to other pages, to documents such as PDFs, and to external websites - and appends them to each page's knowledge document as a structured list. Visitors get a working link to the price list instead of a description of where to find it. The list is built by the extension, never by the AI model, so a URL can never be shortened, reworded or invented, and it costs no tokens. Navigation, breadcrumbs and footers are removed by three independent mechanisms (Contao's own `indexer::stop` markers, structural rules, and a cross-page frequency analysis), and links inside protected member areas are never collected. Links *to* a protected page are filtered from the page tree too, including inherited protection, even when Contao's search-index entry is stale. The protected page's content never enters the knowledge base; `docs/features/page-links.md` explains the case in full. New settings in the OpenAI configuration: **Collect links from the pages** (on by default), **Link types to include**, **Exclude links** and **Add a link and document directory**. Requires `contao:migrate` (new table `tl_openai_page_link`, four new columns). **Note:** the first synchronisation after the update re-uploads every page once, because the page documents change; later runs are incremental again. See `docs/features/page-links.md`.
- **New chat module setting "Open links in"** (German: „Links öffnen in"). Controls where links in chatbot answers open: **New tab (all links)** - the default and the previous behaviour -, **New tab for external links and documents** (links to pages of your own website stay in the current tab, while external links and downloads such as PDFs open in a new tab so they never replace the page and the visible chat), or **Same tab (all links)**. A leading `www.` is ignored when comparing hosts, so `example.com` and `www.example.com` count as the same site. `mailto:`/`tel:` links are unaffected; they never opened a tab. Existing modules and custom chat templates keep the previous behaviour without any change. Requires `contao:migrate` for the new column. See `docs/features/link-target.md`.

- **A reader page's knowledge document is now named after the page (premium).** News, FAQ and event entries share their reader page and are merged into one document. That document used to take its title and its source link from whichever entry URL happened to sort first - so the chatbot's document for "Aktuelles" was headed with one article's browser title, and it kept that title even after the article was deleted, because its row in Contao's search index outlives it. The document is now named after the page itself (as in the site structure) and cites the reader page's own URL. **Note:** pages whose title or URL changes this way are re-uploaded once on the next synchronisation; pages that keep both are left untouched.
- **The vector store file list explains itself and shows the indexed text (premium).** The new list gained a **"Inhalt anzeigen"** button per row, which shows exactly the text that was uploaded for that page (served from the stored run manifest - OpenAI does not allow reading the content of synced files back). A new column **"Indexierte URLs"** shows how many indexed URLs went into a page's document: 1 for an ordinary page, one per entry for a news/FAQ/event reader page, which is where those entries become visible at all. A note at the top of the list states that manually uploaded files are not part of it and are never touched by the synchronisation, and that entries disappear on their own once a page leaves the sync - the list is the synchronisation's own state and therefore has no delete function. The misleading "only one configuration is shown" note is gone; it now appears only on installations that still carry more than one configuration from an older version.
- **You can now see which OpenAI file holds which page (premium).** Each page is uploaded as its own file in the vector store, and the OpenAI platform only lists those files by their file ID - which said nothing about the page behind it. Three additions close that gap: a new backend list **AI Tools → "OpenAI Vector-Store-Dateien"** (page, URL, part, size, file ID and status, searchable by file ID, with a link into the site structure and to the live page), a **"Indexierte Dateien anzeigen"** button on the auto-sync dashboard that opens that list for the configuration at hand, and the **downloadable run manifest**, which now names the vector-store file of every page plus what happened to it (added, updated, unchanged, failed). Uploads also carry a readable file name in the OpenAI platform (`seite-7-preise.md`) instead of a random one; files uploaded by an earlier version keep their old name until the page changes and is re-uploaded. The new list is read-only: it is the synchronisation's own state, and deleting a row would upload the page twice. See `docs/features/indexing.md`.

### Fixed
- **A page removed from the chatbot is now really gone from it (premium).** When a page left the synchronisation - deleted, unpublished, protected, or simply unticked - the extension asked OpenAI to delete its document and then forgot the file, without ever checking whether OpenAI had agreed. A rejected request (an expired key, a rate limit that outlasted the retries, a server error) looked exactly like a successful one, so the document stayed in the vector store, kept answering visitors, and no later run could find it again: the only reference to it had been deleted along with it. The run reported the page under **"entfernt"** all the same. Deletions are now confirmed before anything is forgotten. A deletion OpenAI does not confirm keeps its entry in **"OpenAI Vector-Store-Dateien"** with the status **"Entfernung ausstehend"**, is retried at the start of every following run until it succeeds, and is not counted as removed. The run is reported as **"teilweise"** with a note saying how many documents are still in the store, because this is the one failure nobody can see from the outside: the website looks right, the page is gone from the selection, and the chatbot still answers from it.
- **Protecting a page now removes it from the chatbot, even before the next crawl (premium).** Whether a page was member-only was read from Contao's search index. But Contao does not clear a page's index entry when you tick **"Seite schützen"** - it reacts to an alias change, to the search settings, to `robots=noindex` and to deletion, and to nothing else - and the crawler cannot correct the entry either, because a protected page no longer answers an anonymous request. The stale entry therefore kept saying "public" indefinitely, and the page kept being uploaded to a knowledge base that answers anonymous visitors, kept counting against the plan's page allowance, and kept being linked from the **"Weiterführende Links"** blocks of other pages. Protection is now resolved from the site structure itself, including protection inherited from a parent page, and all three follow the same answer. If that protection lookup fails, the synchronisation now stops without changing the knowledge base instead of assuming that every page is public; very deep trees and large protected URL sets are handled without an arbitrary cutoff. **Note:** the removal now also happens on a run that stops early because the search index came back empty - previously the very case this is about could abort before reaching it.
- **An unconfirmed OpenAI ingestion no longer replaces a working page (premium).** After uploading a page the extension waits up to 30 seconds for OpenAI to finish indexing it. A failed status request or a file still being processed used to count as finished, so a later server-side failure became permanent. The first fix marked such a candidate as **"Wird verarbeitet"**, but still swapped it in immediately and deleted the page's previous working revision. An unconfirmed candidate is now rolled back instead: the previous page document (and, during the 2.2 upgrade, the legacy knowledge file) stays available, the run is reported as partial, and the next run tries again. Processing rows written by a pre-release build are still recovered safely.
- **The knowledge base from before the update is no longer thrown away before its replacement exists (premium).** The first synchronisation after updating to 2.2 replaces the single large document of earlier versions with one document per page. That old document was deleted at the very beginning of the run, before a single page had been uploaded - so a run that then failed at the upload, at OpenAI's indexing or at the database left the chatbot with neither the old knowledge base nor the new one. It is now deleted last, and only after every page has a working replacement. If the deletion itself is not confirmed, its reference is kept so the next run retries it, instead of leaving an invisible duplicate of the whole site in the vector store forever. Every existing premium installation walks this path exactly once.
- **A file that could not be added to the vector store is no longer reported as uploaded.** Uploading a document to OpenAI and attaching it to the vector store are two steps, and the second can fail on its own. The file was recorded as **"uploaded"** and confirmed with **"File uploaded successfully"** before the attachment was even attempted - so a document the chatbot can never read was listed as ready, next to the attachment error, on the same screen. The attachment now happens first: if it fails, the file is recorded as failed, no success message is shown, and the uploaded document is removed from OpenAI again so it does not sit there as storage nobody can reach.
- **The daily message limit now holds against simultaneous requests.** The limit was checked before the request to OpenAI and only booked after it. Requests arriving at the same moment therefore all saw the same last free message and all went through, which is exactly the situation the limit exists for: a flood spread across many addresses, where nothing else caps the cost. A message is now reserved before the request in a single database operation, so only one request can take the last one however many ask together. A reservation is handed back when the request provably never reached OpenAI, so an invalid key or an OpenAI outage still cannot use up the day's budget. Requires `contao:migrate` for the new table `tl_openai_chat_budget`; until then the previous, non-atomic check remains in force.
- **The dashboard no longer reports that scheduled synchronisations are not running while they demonstrably are (premium).** The two boxes **"Server-Cron (Heartbeat)"** and **"Zeitplan (automatische Synchronisierung)"** looked like two independent findings but were one and the same measurement shown twice: a single timestamp Contao writes for its own marker job. When that timestamp read badly, the first box said "Veraltet" and the second concluded "Läuft nicht automatisch - geplante Synchronisierungen werden nicht gestartet", directly above a history table listing ten successful runs with the trigger "Zeitplan". The two boxes now answer different questions and each answers it from the right source. **"Zeitplan" is decided by evidence**: the last synchronisation this configuration actually ran from the cron. Such an entry can only have been written by the scheduled job, which refuses to run inside a web request, so it is proof that the server cron fired - and it now outranks any timestamp. The box reports **"Läuft"** with the last scheduled run and the next one, **"Überfällig"** when scheduled runs happened and then stopped (a far more useful statement than "never set up"), or that the first run must still be started by hand. **"Server-Cron" stays an honest reading of the heartbeat** but stops raising false alarms: it no longer counts a synchronisation in progress as a missed heartbeat - the run occupies the cron process for its whole duration, so on servers that do not allow two cron runs at once a 14-minute silence is normal, while the old limit was two minutes. The limit is now **30 minutes**, sized for the hosting packages that offer no minutely cron at all: a cron running every 5, 15 or 30 minutes is perfectly sufficient for this extension - a Contao cron job is re-evaluated whenever `contao:cron` runs, so the synchronisation simply starts at the next possible tick - and such an installation used to show a permanent warning for a setup that was working exactly as intended. A timestamp that cannot be true (typically because the command line and the web server are configured with different time zones, which shifts it by whole hours) is now reported as **"Nicht ermittelbar"** together with the likely cause, instead of as a stopped cron. And whenever the reading looks bad while scheduled runs are provably happening, the box says so in the same breath rather than sending you to the setup guide for a cron that is working.
- **A link listed under "Weiterführende Links" is now named by the most useful of its link texts (premium).** When a page links to the same target more than once - typically once normally and once as a jump to a section of it - the entries are merged into one, and the label was decided by length alone. That is right against "mehr" or "weiterlesen", but wrong for three kinds of longer text that say less: the anchor text of a jump link ("#kontakt" beat a page called "Kontakt" by a single character), a pasted web address (long by nature, and it only repeats the address the entry already links to), and the substitute label the extension builds itself when a link has no text at all, which could outrank one written by a human. Those three now lose to any real text; between two real ones the longer still wins. **Note:** pages whose link list changes this way are re-uploaded once on the next synchronisation. In "KI-optimiert" mode this costs no tokens - the link list is added after the AI step and is not part of what is cached.
- **An update installed without `contao:migrate` now says so instead of failing with a database error (premium).** Several features of this version add columns and a table, and the synchronisation refuses to start until they exist. That check only asked about part of what it writes, so an installation updated from 2.1.x - which already has the older columns - passed it and then failed on the first database write: **"Jetzt manuell synchronisieren"** answered with a raw SQL error about an unknown column, and a scheduled run aborted the same way. The check now covers every required internal table and every column a run writes - including the one in the run history, which is written at the very end, so an installation whose database update was only partially applied could previously crawl the whole website, pay for every AI rewrite and upload every file, and only then fail. A pending migration is reported as one clear sentence naming the install tool, and the scheduled run skips quietly with a note in the system log until the database is up to date. **The dashboard itself now says the same thing:** opening **KI-Tools → "OpenAI Vector-Store-Auto-Update"** before the migration used to answer with a raw SQL error about an unknown column - on the very page an operator opens to find out why the synchronisation stopped. Deleting a configuration in that state no longer fails either - it could previously be aborted halfway, after the vector store at OpenAI had already been deleted. The chatbot itself keeps answering visitors throughout, and neither your configuration nor your indexed pages are touched.
- **A slow but healthy synchronisation is no longer started a second time by cron (premium).** OpenAI retries and multi-part page uploads can spend several minutes without finishing a page. Those waits now refresh the running job's lease before every OpenAI attempt, so cron does not mistake useful work for a crashed process and launch a concurrent duplicate. Deletion-attempt tracking is reset for each configuration run as well, so a later run in the same cron process can retry an earlier unconfirmed removal.
- **Saving the OpenAI configuration is fast again, and a changed API key is no longer silently discarded.** Several backend callbacks were registered twice - once in the DCA and once as a service - so Contao ran them twice per save. For the API key that meant two live validation calls to OpenAI: the second one stalled on the reused connection until its 15-second timeout, so every save took about 15 seconds longer and wrote a misleading "API key validation failed during save" into the log. Worse, that failed second run returned the **previously stored** key, so entering a new API key could appear to save while the old one was kept. Eight further callbacks across the configuration, prompt and file screens ran twice in the same way - among them the vector-store deletion, which was attempted twice, and the validation of every prompt setting. All of them now run once.
- **A synchronisation interrupted by a server cache rebuild now says so.** If the site is updated or deployed while a synchronisation is running - or the command line uses a different PHP version than the website - the crawl dies with a page of raw PHP warnings about a missing file in `var/cache`, which was shown verbatim in the sync history. That case is now recognised and reported in plain words, with the advice to simply start the synchronisation again; the full output goes to the system log. Nothing is indexed and nothing in the vector store is changed when this happens.
- **"Schlüssel prüfen" no longer calls a key invalid when the check could not run at all.** If the request never reached the server - no connection, DNS failure, an unreachable backend, a browser extension blocking it - both the OpenAI API key and the licence key button reported **"ungültig"**, although nothing had been checked. They now report "Prüfung fehlgeschlagen" and leave the field's colour alone. For the licence key this had a second, worse effect: the failed check switched the licence off in the form, disabled every automatic-synchronisation field and unticked **Synchronisierung aktivieren**. Disabled fields are not submitted, so saving the configuration after such a failed check could silently turn the automatic synchronisation off and drop its settings - triggered by nothing but a moment without a connection. A licence check that cannot reach the licensing server now says so instead of answering "invalid", and leaves the form untouched. The same applies when the licensing server answers but not with a verdict - a rate-limit response, a server error or a maintenance page were all read as "key invalid" and are now reported as a failed check, matching the rule the scheduled licence revalidation already followed.
- **Pages deeper than three clicks from the home page are now indexed too (premium).** The synchronisation runs Contao's crawler before it reads the search index, but relied on the crawler's default depth limit of three link steps from the site root. Anything further away - typically the second page of a news or event list and every article behind it, and deeper page trees in general - was never indexed and therefore never reached the vector store, while the run still reported "success". The crawl now runs without a depth limit. **Note:** the first synchronisation after the update can take noticeably longer and will add the previously missing pages to the vector store; check the page count in the run history afterwards.
- **Leftover theme site roots no longer block the automatic synchronisation (premium).** When no pages are selected explicitly, the synchronisation covers the whole website - but only if exactly one site root exists. Site roots that are not live were counted too, so an installation with a single live website could fail with "more than one site root found" - typically because of an unpublished site root left behind by a theme import. Only roots that are actually live count now: published, and within their start/stop dates if those are set (the same rule Contao itself applies to a page). The dashboard follows the same rule: it used to count every published site root and ignore the start/stop dates, so it could grey out **"Jetzt manuell synchronisieren"** with "no page to crawl" on an installation the synchronisation resolves perfectly well - and, the other way round, show no warning at all for a single site root whose publication period has not started, only for the run to fail afterwards.
- **The subscription page count no longer includes pages that are never synchronised (premium).** Protected pages are excluded from the synchronisation, but they still counted against the page allowance of the plan - and because that limit is enforced when the configuration is saved, a few member-only pages inside the selection could refuse the save with "your selection covers N pages" for pages that would never have become a document, with nothing in the back end explaining the difference. On the smallest plan that is a handful of pages out of twenty. They are no longer counted, and neither are the news, FAQ and event entries rendered on them. Protection is resolved from Contao's page tree with inheritance and combined with Contao's search-index flag, so newly protected pages and protected descendants are both recognised.
- **Member-only pages are no longer uploaded to the vector store (premium).** With the Contao setting `contao.search.index_protected` enabled, protected pages land in Contao's search index - and the synchronisation used to upload them like any other page, so the chatbot could answer anonymous visitors with member-only content. Protected pages are now excluded from the synchronisation. **Note:** if such pages were uploaded before, the next synchronisation removes them from the vector store again; the run manifest counts them under "removed".
- **Custom instructions for the AI-optimised indexing mode reach the model unchanged (premium).** On Contao 5.3 and 5.7, characters such as `=`, `(`, `)` and quotation marks were stored encoded and were passed on to the AI model in that form, which weakened the instructions.
- **Backend file uploads no longer depend on the `public/files` symlink.** File paths are now resolved the way Contao itself resolves them; on installations without that symlink, uploading a file to the vector store failed with "File not found".
- **The chatbot now always uses the same configuration.** Previously the most recently saved configuration was used. On installations that still carry more than one configuration from an older version, saving the other one silently switched the API key, vector store and prompt of the live chatbot. The chatbot now uses the configuration the backend also edits, and writes a warning to the log if more than one exists.
- **The chat endpoint now rejects excessively long messages** (limit: 4000 characters). The endpoint is public and OpenAI bills every request by token, while the rate limits count messages rather than length - so a single very large message could cost far more than the daily message limit suggests.
- **The daily message limit is no longer used up by failed requests.** Questions that never reached OpenAI - for example because of an invalid API key or a temporary outage - counted towards the daily limit, so the chatbot could report "daily limit reached" although it had not answered anything.
- **Raising the daily message limit after it was reached now works immediately.** Previously, once the limit was hit, raising it again in the backend had no effect - the chatbot kept refusing every message with "daily limit reached" for the rest of the day, with no way to recover other than waiting until the next day.
- **A question that takes too long is no longer answered twice.** The chat widget gives up after two minutes and invites the visitor to try again, but the server was allowed three - and closing the connection in the browser does not stop the work already running on the server. The first question could therefore still be answered, be billed, and be added to the conversation after the visitor had been told it failed, so the retry arrived into a conversation that had silently moved on. The server now finishes within the widget's two minutes, with the automatic repeat of a failed request counted inside that time rather than added on top of it.
- **The 5000-page ceiling of the crawl now holds for very wide page trees (premium).** The limit was checked before each group of subpages was read and the whole group added afterwards, so a single page with several thousand subpages directly beneath it could exceed it.
- **The chat widget now honours "reduce motion" completely.** The setting switched off the pulsing of the chat button only, while the panel still faded in and out, the message list still scrolled smoothly and the buttons still animated on hover. All of it now stops for visitors who have asked their system for reduced motion. Nothing outside the chat widget is affected.
- **A failed cleanup of an old assistant is retried on the next update instead of being given up on.** The one-time cleanup of leftover assistants from the 1.x line dropped its local reference after every attempt, including attempts that established nothing - no API key, no connection, a server error, or a key that had been revoked. The reference is the only way to find the object again, so the cleanup could never be repeated and the leftover stayed on the OpenAI account for good. It is now kept unless the assistant is confirmed deleted or confirmed already gone, so simply running `contao:migrate` again finishes the job once the cause is fixed.
- **An English visitor no longer gets German error messages.** The chat endpoint decided the language of its error messages by searching the whole browser language header for German, so a header listing English first and German further down as a fallback - the normal setup for someone who speaks both - was answered in German. Only the browser's first choice decides now.
- **The synchronisation history of a configuration with an expired licence no longer grows without end (premium).** Every skipped run added a row, and only the other paths trimmed the table to the documented 30 entries. A configuration whose schedule is still switched on after the licence lapsed therefore added a row per cron tick indefinitely.
- **A synchronisation that finds no indexed pages now explains its own run (premium).** The server cron works through every configuration in one go, and the crawl summary shown in the "no indexed pages" error was not reset between them, so the second configuration could be explained by the first one's crawl - a wrong explanation in the one place an operator looks for the right one.
- **The chat works on installations served from a subdirectory** (e.g. `example.com/cms/`). The chat addresses were built from the domain root, so the widget appeared but did not respond there.
- **Short-lived faults at OpenAI no longer produce an error message in the chat.** When OpenAI (or the content delivery network in front of it) briefly fails, the request is now repeated once automatically - previously the visitor saw "Service temporarily unavailable" although the next attempt would have worked. Requests that may already have been processed are deliberately not repeated, so no question is ever answered - and charged - twice.
- **Technical error pages no longer end up in the log in full.** A failure of OpenAI's content delivery network used to write its complete HTML error page into the log file; it is now reduced to the status code and the first line.
- **The API key check no longer shows technical error details** in the backend, and it now distinguishes "the key is invalid" from "the check could not be carried out" (for example without an internet connection).
- **The OpenAI configuration no longer overflows on small screens.** On phone-sized displays the select fields and help texts of the vector store synchronisation ran past the right edge of the panel, and the premium licence box wasted much of the available width.

### Changed
- **News, FAQ and event entries now have their own subscription allowance (premium).** They have no pages of their own - all of them are shown through a single reader page - so they used to count as one single page no matter how many there were. They are now counted separately from the page limit: Einsteiger 50 entries, Business 300, Enterprise unlimited (the page limits are unchanged). Only entries that really reach the chatbot are counted - published, and actually indexed. **Exceeding the entry allowance never blocks anything and never removes content:** everything keeps being synchronised, the run is marked "partial" and asks for an upgrade.
- **The vector store synchronisation settings are now visually grouped.** The schedule and the link options each sit in their own box, and the system prompt now sits directly below the indexing mode it belongs to instead of at the very end.
- **The chat module now states that the chat endpoint is public.** Contao's "Protected / member groups" option hides the chat window, but the chatbot still answers anyone who can reach the website - so confidential content does not belong in the knowledge base. See [docs/security/rate-limiting.md](docs/security/rate-limiting.md).
- **A schedule that keeps the website almost permanently under crawl is now flagged on the dashboard (premium).** Every synchronisation crawls the **whole** website again, not only the pages selected for the knowledge base - deliberately, because a knowledge base that mirrors the site must not stop three clicks from the home page. On a short schedule that adds up fast, and nothing in the backend said so: an hourly schedule on a site of a few thousand addresses means thousands of page requests every hour, a correspondingly large Contao log, and - where other search extensions are installed - their indexers running along with each crawl, because they all hang off the same crawl. The dashboard now compares the interval against how long this installation's last run actually took and, once a run occupies more than about a sixth of its own interval, names both figures and suggests a longer interval. It is advice, not a restriction: a small site can afford a short schedule, and nothing is blocked.

### Security
- **Reading the chat history now also requires the security token**, not just the session cookie. The chat window also stops requesting the history on every page view: it is only fetched once the visitor has actually written something, which saves a request on every page of the website. **Note:** a conversation that was already running during the update reappears as soon as the visitor sends their next message - the chatbot still knows the conversation, only the displayed transcript starts empty once.

## [2.1.3] - 2026-07-18

### Added
- **New prompt setting "Search results per question"** (default: 8, range 1-20). Controls how many text sections from the synced website content the AI may read per answer. Lower values keep answers focused and reduce cost per question; higher values help with broad questions that combine content from many pages (e.g. intranet research). Previously every question always retrieved up to 20 sections.
- A pseudonymous per-visitor identifier (SHA-256 hash of the session id, not reversible) is sent to OpenAI as `safety_identifier`, so potential abuse is attributed to a single visitor instead of the site owner's whole API key.

### Fixed
- **Long chats no longer fail with "Service temporarily unavailable".** Once the stored conversation (including the retrieved website excerpts of earlier turns) outgrew the model's context window, OpenAI rejected every further question with HTTP 400 - especially quickly on smaller models such as gpt-4o-mini. Responses are now requested with `truncation: auto`, so OpenAI trims the oldest turns instead of failing, and if a request is still rejected the chat transparently continues on a fresh conversation instead of showing an error.
- **A stale conversation no longer breaks the chat until the session expires.** If the stored conversation is gone on OpenAI's side (deleted, expired, or the API key was switched to another account), the chat now transparently continues on a fresh conversation. Switching the active configuration also proactively starts a new conversation instead of producing "not found" errors.
- **Models that reject `temperature`/`top_p` (e.g. reasoning models) work now.** The rejected parameter is stripped and the call repeated; the rejection is remembered per model so only the first message after a model switch pays an extra (unbilled) round-trip. The model dropdown also no longer offers models that cannot power a text chat (audio, image, video, embedding, moderation).
- **Reloading a long chat now shows the latest messages.** The history restore previously loaded the oldest 100 conversation items, so long conversations reappeared without their newest turns.
- Transient OpenAI failures (connection failures before the request was processed, HTTP 429/503) are retried once after a short backoff instead of immediately showing an error. Timeouts are deliberately not retried to avoid processing a message twice.
- The Responses call now has a wall-clock time cap (previously only an inactivity timeout), and the chat widget shows a friendly "taking too long" message (DE/EN) after 2 minutes instead of a spinner that runs until a proxy gives up.

### Changed
- CI now tests against both supported Contao lines: 5.3 LTS on PHP 8.2 and 5.7 on PHP 8.3 (the end-of-life 5.4-5.6 lines are no longer resolved into any CI job).

### Notes
- Run `contao:migrate` after updating (new `tl_openai_prompts.max_num_results` column). The chat itself works before the migration (the new setting then uses its default of 8), but editing prompts in the backend requires the migrated schema.
- Visitors chatting during the upgrade keep their running conversation - existing sessions are adopted, not reset.

## [2.1.2] - 2026-07-18

### Fixed
- **API keys stored before 2.1.0 could be unreadable for the CLI sync after an upgrade** ("No usable OpenAI API key"). Stored keys are now automatically re-encrypted with the current key on first use - no manual re-entry needed. Keys in the pre-1.0 base64 format are also migrated correctly.
- **Sync setup checks now match what the sync actually does.** The search-index check is scoped to the selected pages instead of the whole index, and an empty search index no longer blocks the sync (each run starts the crawler itself, so this is now a non-blocking note).
- The sync dashboard warns (and blocks the manual run) when the selected pages span more than one website domain - one license covers one domain.
- Sync errors and setup hints now name the actual cause (pages missing from the search index, missing root domain name, unusable API key) and explain how to fix it.
- The first-sync hint in the vector store sync settings was partly invisible in the Contao backend; it is visible again and links to the dashboard's setup checklist.
- The prompt template field is disabled in "faithful" indexing mode, where it has no effect; the stored template is kept and becomes editable again in "AI-polished" mode.

### Changed
- The sync dashboard shows the license tier badge in all active license states (trial, grace period, payment problem, cancelled but still running).
- Backend text and style polish: sync history labels ("Last 10 syncs" / "Full history"), hint that the initial upload can be deleted after the first sync, theme-aware chat scrollbars, dash cleanup in translations.

## [2.1.1] - 2026-07-16

### Changed
- Pinned the `symplify/easy-coding-standard` dev dependency to 13.2.3 to keep CI code-style checks stable.
- GitHub release notes are now generated from CHANGELOG.md by the release workflow.

No functional changes for users of the extension; identical runtime behaviour to 2.1.0.

## [2.1.0] - 2026-07-16

### Added
- **Premium add-on: automatic vector-store updates.** Keeps the OpenAI vector store in sync with selected Contao pages (manual or scheduled runs, backend status dashboard). Requires a [premium subscription](https://licenses.juhe-it-solutions.at/en/openai-assistant/help).
- **Chat rate limiting - on by default after upgrade.** Two new settings in the OpenAI configuration: per-IP limit (`chat_ip_rate_limit`, default 10/minute) and daily message cap (`chat_daily_limit`, default 1000/day); `0` disables. Raise or disable the IP limit on intranets/NAT where many users share one IP. See [docs/security/rate-limiting.md](docs/security/rate-limiting.md).
- **Link shortening - on by default after upgrade.** New AI-Chatbot module checkbox **Shorten plain URLs** (`tl_module.shorten_urls`, default on): plain URLs in bot answers are rendered as short localized labels ("Download" / "Seite aufrufen" / "Visit page") instead of the full URL. The complete URL stays in `href` and `title`; Markdown links with descriptive text keep it and show the URL as tooltip. Disable the checkbox to restore full-URL rendering. See [docs/features/link-shortening.md](docs/features/link-shortening.md).

### Changed
- **Licensing:** the core extension remains LGPL-3.0-or-later; the new premium add-on files are proprietary (see [`LICENSE-PREMIUM`](LICENSE-PREMIUM)). All earlier releases remain entirely LGPL.
- **Phone autolinking** in chat answers now requires a leading `+` or a phone cue ("Tel.", "Rufen Sie an", …) before the number, so invoice numbers, ISBNs, and dates are no longer turned into `tel:` links.

### Fixed
- **Frontend chat links:** more robust rendering of model-mangled URLs (CJK-bracket-wrapped or decorated URLs, malformed Markdown echoes, line-wrapped URLs); repeated identical links are no longer collapsed into one; URL credentials never appear in tooltips or screen-reader labels.

### Security
- Frontend chat messages are HTML-escaped before formatting (XSS hardening).
- **License validation robustness:** rate-limit (429) and server-error (5xx) responses from the licensing server are now treated as temporary outages covered by the seven-day grace period instead of deactivating a valid license; entitlement data is only accepted from well-formed 2xx responses.

### Notes
- Run `contao:migrate` after the update (new database columns).

## [2.0.2] - 2026-07-01

### Fixed
- **Frontend chat links:** Models sometimes wrap long URLs with a newline at `?`, `&`, `/`, `=` or `#`; after the newline-to-`<br>` conversion these breakpoints landed inside the link text and broke auto-linking. `<br>` is now allowed at those breakpoints and stripped from the resulting `href`.

## [2.0.1] - 2026-06-11

### Fixed
- **Frontend chat links:** Improved chatbot message link rendering for Markdown links, angle-bracket URLs, `www.` links, `mailto:` and `tel:` links, and URLs containing query strings, fragments, or balanced parentheses. Autolinking now avoids modifying already-rendered anchors and keeps trailing sentence punctuation outside clickable links.

## [2.0.0] - 2026-04-16

> ⚠️ **Breaking change release.** This version replaces the OpenAI Assistants API (which OpenAI is sunsetting on **August 26, 2026**) with the **Responses API** and **Conversations API**. The upgrade is automated via two migrations (table rename + orphan cleanup), but there is no downgrade path back to 1.x because remote OpenAI Assistants are deleted during the upgrade. See the [Upgrading from 1.x](docs/development/troubleshooting.md#upgrading-from-1x) section for details.

### Added
- New `tl_openai_prompts.prompt_id` (VARCHAR 128) and `tl_openai_prompts.prompt_version` (VARCHAR 32) columns: you can optionally reference a prompt managed in the OpenAI dashboard. When set, the dashboard-managed prompt overrides the local `Instructions` field.
- New `src/Service/OpenAiResponder.php` service that encapsulates the Responses API runtime: creating conversations, sending messages, retrieving conversation items, and clearing sessions.
- New `src/Service/EncryptionService.php` centralises API key encrypt/decrypt/validate logic. Supports `OPENAI_API_KEY_{configId}` environment variable override via `getApiKeyForConfig()`.
- Conversation history retrieval for the frontend chatbot: chat state is rehydrated from `GET /v1/conversations/{id}/items` on page reload instead of relying on `/v1/threads/{id}/messages`.
- Full German + English localisation for the new "Prompts" terminology and the new `prompt_id` / `prompt_version` fields.

### Changed
- Runtime migrated from `POST /v1/threads/{id}/runs` to `POST /v1/responses`. Each request carries the conversation id, the prompt configuration (model, instructions or `prompt` reference, `temperature`, `top_p`, `max_output_tokens`), and the File Search tool when a vector store is attached.
- Session storage key renamed from `openai_thread_id` to `openai_conversation_id`. Legacy `openai_thread_id` keys are silently unset on first request after upgrade.
- `OpenAiPromptsListener::validateModelViaApi()` now validates model compatibility by sending a minimal `POST /v1/responses` ping (`input: "ping"`, `max_output_tokens: 16`, `store: false`) instead of creating and deleting a temporary Assistant.
- Database table `tl_openai_assistants` renamed to `tl_openai_prompts` (migration `Version20260416000000RenamePromptsTable`).
- Backend DCA, language files, and navigation labels now say "Prompts" instead of "Assistants".
- `OpenAiConfigListener::deleteVectorStore()` no longer deletes remote Assistants when a config is removed; prompts are purely local now. Vector-store-and-files cascade cleanup is unchanged.
- Listener service renamed: `OpenAiAssistantsListener` → `OpenAiPromptsListener`. DCA callback tags updated to target `tl_openai_prompts`.
- `OpenAiFilesListener` no longer sends the `OpenAI-Beta: assistants=v2` header on `DELETE /v1/files/{id}` calls (it's only kept on vector store endpoints that still require it).

### Removed
- All runtime calls to the OpenAI Assistants API:
  - `POST /v1/assistants`
  - `POST /v1/assistants/{id}`
  - `DELETE /v1/assistants/{id}` *(still used once by the cleanup migration - last allowed usage)*
  - `POST /v1/threads`, `POST /v1/threads/{id}/messages`, `POST /v1/threads/{id}/runs`, `GET /v1/threads/{id}/messages`
- `src/Service/OpenAiAssistant.php` is no longer the runtime implementation; a deprecated BC shim now forwards to `OpenAiResponder` to keep 1.x custom integrations working until 2.1.
- The "Sync with OpenAI" button and related `createOrUpdateAssistant` / `deleteAssistant` DCA actions - prompts are local and do not need remote synchronisation.
- `config.onsubmit` / `config.ondelete` DCA callbacks that previously created / deleted remote Assistants.

### Migrated
- **Orphan Assistant cleanup** (`Version20260416000001CleanupOrphanAssistants`): on upgrade, every `tl_openai_prompts` row with a non-empty `openai_assistant_id` triggers a `DELETE /v1/assistants/{id}` on the OpenAI platform (still authorised for cleanup during the sunset window). The local `openai_assistant_id` column is then cleared. HTTP 2xx / 404 / 410 / 401 are all treated as "gone". The migration never throws on HTTP errors and writes a summary (`deleted` / `skipped` / `failed` counts) into the migration result.
- Database table rename + new columns (`Version20260416000000RenamePromptsTable`): idempotent, re-runnable safely.

### Notes
- Users with active chat sessions at upgrade time will see a fresh, empty conversation on their next message - the legacy thread ids were session-scoped in v1.x anyway.
- Runtime API key resolution prefers `OPENAI_API_KEY_{configId}` over DB-encrypted keys. (The one-time orphan cleanup migration reads the DB-stored key for 1.x compatibility.)
- **Important for upgrades from 1.x:** The orphan-assistant cleanup runs in CLI context. If no valid API key can be resolved there (e.g. encrypted key cannot be decrypted in that environment), the migration still clears local legacy references but cannot remove the remote Assistant. In that case, any already existing "OpenAI assistant" must be deleted manually in the OpenAI platform dashboard.
- No changes to files, vector stores, or uploaded documents - these continue to live on OpenAI's platform and keep working with the File Search tool.

## [1.1.3] - 2026-03-04

### Fixed
- **Frontend chat links:** Chatbot output could contain links whose `href` ended with a trailing dot (e.g. `https://example.com/page.html.`), breaking or misdirecting clicks. All `href` values are now sanitized so that trailing dots are stripped before display.
- **Chat history order:** Thread messages are now requested from the OpenAI API with `order=asc`, so conversation history displays in chronological order after page navigation or reload.

## [1.1.2] - 2026-02-24

### Fixed
- **Frontend chat links:** LLM sometimes appended "br" to http(s) link URLs and link text (e.g. `.htmlbr` instead of `.html`). A post-processing step now strips trailing "br" from http(s) links only; mailto: and tel: links are unchanged.

## [1.1.1] - 2026-02-24

### Added
- **Frontend i18n for the AI chat module:** All user-visible strings (placeholder, buttons, labels, titles, errors, disclaimer) are now translated based on the browser’s preferred language. German and English are supported via `contao/languages/de/mod_ai_chat.php` and `contao/languages/en/mod_ai_chat.php`. The controller reads `Accept-Language` from the main request, parses it in priority order, and loads the matching language file; the template and JavaScript use server-provided labels and a small JSON map for client-side strings. Unsupported locales fall back to English. Module-specific titles and messages from the backend still override the translated defaults.

### Changed
- Language detection now respects the order of the `Accept-Language` header (e.g. `en,de;q=0.9` correctly yields English when English is listed first).
- `Accept-Language` is read from the main request via `RequestStack::getMainRequest()` so the chat language follows the visitor’s browser even when the module is rendered in a fragment sub-request.

### Fixed
- **Frontend chat links:** Trailing `<` or `>` could appear in link `href` values and link text, breaking or misdisplaying links. URLs are now sanitized when turning plain URLs into links (strip `<`/`>` from captured URL), all `href="..."` values are cleaned of `<`/`>` in a final pass, and a stray `>` immediately after `</a>` (e.g. from angle-bracket notation or model output) is removed.

### Notes
- No database migration required. Clear frontend cache after update if needed.

## [1.1.0] - 2026-02-18

### Added
- Backend "Key prüfen" button for OpenAI config API key field: validate key before save (works in Contao 5.3 and 5.7).
- Dedicated backend JS asset `backend-api-key-check.js` as fallback for button binding.
- Backend CSS for API key check wrapper (placement below input, spinner, result message).

### Changed
- OpenAI config DCA: API key field now uses `xlabel` callback instead of `wizard` for reliable rendering in Contao 5.7.
- `OpenAiConfigListener::apiKeyWizard()`: outputs HTML + data attributes and inline script so the button works without depending on global backend JS in all Contao versions.
- Button is placed below the API key input in both 5.3 and 5.7; input lookup supports `ctrl_<field>`, `<field>`, and `input[name="..."]` for compatibility.
- Removed `fields.api_key.wizard` service callback tag from `config/services.yaml` (wizard registration is via DCA only).

### Fixed
- "Key prüfen" button not visible in Contao 5.7.0 (wizard callback no longer used for password widget in 5.7).
- Button overlapping the API key input in Contao 5.7; wrapper is moved below the field and styled with clear spacing/z-index.
- Button click having no effect when backend JS did not load; inline script in widget ensures validation runs in both 5.3 and 5.7.

### Notes
- No database migration required. Clear backend cache after update.

## [1.0.8] - 2025-09-25

### Changed
- Use configured web directory parameter (`%contao.web_dir%`) to resolve absolute file paths instead of hardcoding `public/`.
- Preserve absolute `%contao.web_dir%` values and only prefix with `%kernel.project_dir%` when relative.

### Fixed
- "File not found" errors on systems with non-default web roots (e.g., custom doc roots or legacy `web/`).
- Improved user-facing error messages for missing files, including resolved web root and attempted absolute path.

### Notes
- No database migration required. Clear cache after update so the container picks up the new service argument.

## [1.0.7] - 2025-08-23

### Fixed
- **CRITICAL**: Database migration issue with disclaimer_text column causing "Data truncated" error in MySQL
- Updated DCA configuration to use proper Doctrine schema representation for Contao 5.3+
- Removed database default values for TEXT columns (not supported in MySQL)
- Enhanced migration system to handle TEXT columns properly without database defaults

## [1.0.6] - 2025-01-27

### Added
- Set default value for disclaimer in frontend chatbot
- Auto-focus on chatbot input field for better user experience

### Changed
- Improved release script with better error handling and validation

## [1.0.5] - 2025-08-23

### Added
- Enhanced frontend module with improved user experience
- New documentation structure with feature guides
- Migration system for database schema updates

### Changed
- Updated module templates and styling for better responsiveness
- Improved JavaScript functionality for chat interactions
- Enhanced language files with better translations
- Updated PHPStan configuration for stricter type checking

### Fixed
- Various code style and formatting improvements
- Enhanced documentation clarity and structure

## [1.0.4] - 2025-08-13

### Fixed
- Load services via `AbstractBundle::loadExtension` with `ContainerConfigurator`; fixes service loading after v1.0.3 where services might not have been registered properly.

## [1.0.3] - 2025-08-13

### Added
- Surface assistant failure causes and bind vector store per run
- Add `.gitattributes`
- Add project homepage
- CI/CD pipeline documentation and quick reference

### Changed
- Update to PHPStan 2.x, adjust configuration and code accordingly
- Preserve system instructions exactly as entered (decode entities; preserve quotes/brackets)
- Adapt regex to prettify bot answers
- Update CI/CD workflows and documentation

### Fixed
- Code style issues and ECS workflow inconsistencies
- Default value of DCA field `top_p`
- Mobile: ensure chat window is collapsed by default on small screens
- Various CI/CD pipeline fixes; remove invalid `--dry-run` flag in ECS check
- Add explicit nullable type hint for PHP 8.4 compatibility

### Removed
- `composer.lock` from the repository

## [1.0.2] - 2025-07-07

### Added
- Complete CI/CD pipeline implementation and production readiness

### Changed
- Prevent auto-focus on mobile devices to avoid unwanted keyboard popups

### Removed
- Obsolete package metadata and logo/preview files from `contao` directory (moved to package-metadata repository)
- One GitHub Actions workflow to simplify development

## [1.0.1] - 2025-07-05

### Added
- Optimized package metadata, company logo, and preview image for the Contao extension marketplace

### Changed
- Bump internal version metadata to 1.0.1

### Fixed
- Critical CSS issue on mobile devices

### Removed
- Unnecessary `contao/README.md` file

## [1.0.0] - 2025-07-03

### Added
- Initial release of Contao OpenAI Assistant Bundle
- OpenAI Assistant integration with backend management
- Frontend chatbot with customizable styling
- File upload support for knowledge base
- Secure API key management with encryption
- Vector store integration for file processing
- Model selection with validation
- CSRF protection and security features
- Responsive design with theme support
- Comprehensive documentation and guides

### Changed
- CI/CD pipeline implementation with GitHub Actions, PHP 8.2 testing, code quality checks (ECS/PHPStan), security scanning, and automated release workflow
- Simplified testing approach focusing on code quality, formatting, and security
- PHP 8.2+ compatibility

### Security
- API key encryption using AES-256-CBC
- CSRF token validation for all forms
- Input sanitization and validation
- Secure file upload handling

- Environment variable support for API keys
