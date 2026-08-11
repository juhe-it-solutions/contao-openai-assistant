# Upgrading to 3.0.0

For installations moving to **Contao 6**. The [CHANGELOG](../CHANGELOG.md) has the detail of
every individual change.

**3.0.0 is a platform change, not a feature change.** It requires Contao 6.0 and PHP 8.4, and
that is the only breaking difference. No feature was removed, no setting was renamed, and the
database schema is identical to 2.2.0. The 2.x line stays maintained in parallel for Contao 5.3
and 5.7 and carries the same features - if you are staying on Contao 5, you are not missing
anything by not upgrading here.

---

## The order matters

Contao 5 to Contao 6 is **Contao's own migration**, and this extension follows it rather than
driving it. Doing the two in the wrong order leaves you with an extension that cannot install.

1. **Get to 2.2.0 first, on Contao 5.** If you are on 2.1.4 or earlier, read
   [Upgrading to 2.2.0](upgrading-to-2.2.0.md) and complete it - including the first
   synchronisation, which rebuilds the whole knowledge base once. Do that while you are still on
   familiar ground rather than on the far side of a Contao major upgrade.
2. **Upgrade Contao 5 to Contao 6**, following the Contao project's own upgrade notes, with this
   extension still at 2.2.0. 2.2.0 does not claim Contao 6 support, so treat this as the step
   where the chatbot may be down.
3. **Then upgrade this extension to 3.0.0:**

   ```bash
   composer require juhe-it-solutions/contao-openai-assistant:^3.0
   ```

4. **Run `contao:migrate`.** The schema is the same as 2.2.0, so on an installation that already
   ran the 2.2.0 migration this reports nothing to do. Run it anyway - it is what proves it.
5. **Purge the Contao and page caches**, and hard-reload the backend once. Contao 6 navigates
   the backend with Turbo, and a cached copy of the previous backend JavaScript is the usual
   cause of buttons that appear dead right after the upgrade.

## Afterwards

Smoke-test the same things the 2.2.0 guide lists: send a chat message, reload and check the
transcript, use the **"Schlüssel prüfen"** and licence check buttons, download a run manifest,
and open the vector-store file list. On Contao 6 also open the OpenAI configuration, the prompt
list and the file list themselves - the backend list rendering is the part this release adapted
most, and it is where a problem would show up first.

## Downgrading

**Going back to 2.x means going back to Contao 5.** 3.0.0 changes no data, so the extension
itself has nothing to undo: reinstalling `^2.2` restores the previous line and every
configuration, prompt, file and vector-store record is read by it unchanged. But 2.x does not
install on Contao 6, so a downgrade of the extension alone is not a working state - you would be
reverting the Contao upgrade too, which is a Contao-level restore from your backup, not a
Composer operation.

This is the reason for step 1 above: complete the 2.2.0 upgrade and its first synchronisation
while you can still go back easily.

## Expected on Contao 6 - not faults

**The backend looks slightly different.** Contao 6 renders list views itself, and the columns of
the vector-store file list and the sync log are now produced through Contao's own label
mechanism. The same information is shown; the framing is Contao's.

**Rich text in the chatbot's own backend hints is sanitised by Contao.** Contao 6 routes rich
HTML through `sanitize_html`, its content-security-policy handling and its insert-tag pipeline.
That is the platform's behaviour, applied here through the same helpers Contao's own templates
use.

---

## For 2.x users staying on Contao 5

Nothing to do. The 2.x line receives the same fixes; the two lines differ only in the platform
they run on. `composer require juhe-it-solutions/contao-openai-assistant` without a version
constraint keeps resolving 2.x for as long as you are on Contao 5.
