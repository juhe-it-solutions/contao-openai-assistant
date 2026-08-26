# Upgrading to 3.0.0

For installations moving to **Contao 6**. The [CHANGELOG](../CHANGELOG.md) has the detail of
every individual change.

**3.0.0 is a platform change.** It requires Contao 6.0 and PHP 8.4. No feature was removed and
no setting was renamed. Install the latest 2.x release and run its migrations before changing
platforms so that the database is already at the schema expected by 3.0.0.

---

## The order matters

Contao 5 to Contao 6 is **Contao's own migration**, and this extension follows it. The 2.x
package requires Contao 5, while 3.x requires Contao 6. Composer therefore has to change both
requirements in one dependency-resolution operation. Do not try to install Contao 6 while
leaving this extension constrained to 2.x.

### Starting from Contao 5.3 with extension 2.2.0

This starting point is supported, but it is not a one-command upgrade. Version 2.2.0
already contains the extension schema changes that 3.0.0 needs; there are no additional
extension migration files between `v2.2.0` and this 3.x branch. The Contao 6 platform
migration, PHP 8.4 requirement, and DBAFS re-hash still apply.

Before changing the platform, finish the 2.2.0 update while the site is on Contao 5:

```bash
php bin/console contao:migrate
php bin/console contao:filesync
```

Then update to the latest Contao 5.7 release and latest 2.x release (currently 2.2.1),
and run the migration and a successful synchronisation described below. Do not assume
that a migration was applied just because 2.2.0 is installed; the migration history is
the source of truth.

1. **Update to the latest Contao 5.7 and extension 2.x releases first.** If you are on 2.1.4 or earlier, read
   [Upgrading to 2.2.0](upgrading-to-2.2.0.md) and complete it - including the first
   synchronisation, which rebuilds the whole knowledge base once. Run `contao:migrate` and
   complete a successful synchronisation while the site is still on Contao 5.
2. **Synchronise files and take a backup.** Contao 6 changes the DBAFS hash algorithm, so make
   sure the filesystem and database agree before upgrading:

   ```bash
   php bin/console contao:filesync
   ```

   Then take a tested database and file backup. See Contao's
   [6.0 API upgrade notes](https://github.com/contao/contao/blob/6.0/UPGRADE.md) and
   [Contao 6 release overview](https://contao.org/de/news/contao-6-0-veroeffentlicht) before
   changing the project.
3. **Change Contao and this extension together.** In Contao Manager, select Contao 6.0 and set
   this extension's version constraint to `^3.0` before applying the changes. Review the dry-run
   result, then let the Manager update both packages in the same operation.

   For command-line deployments, first update every root `contao/*-bundle` requirement in
   `composer.json` to Contao 6 (`^6.0`, with `contao/manager-bundle` at `6.0.*`) and change this
   package to `^3.0`. Then resolve the complete set together:

   ```bash
   composer update --with-all-dependencies
   ```

   Do not update this extension separately while the project still has Contao 5
   constraints; the v2.x package and the Contao 5 platform cannot be part of the
   same solve as v3.x.

   Commit the changed `composer.json` and `composer.lock` only after reviewing the resolved
   package versions. Follow Contao's official migration notes for any additional project-level
   changes.
4. **Use removal and reinstall only as a fallback.** If your deployment tooling cannot change
   both sets of constraints in one operation, remove this extension while the project still runs
   Contao 5, upgrade Contao to 6, and then require the extension at `^3.0`. Removing the package
   does not remove its database tables, but keep the backup until the migration is verified.

   ```bash
   composer remove juhe-it-solutions/contao-openai-assistant --with-all-dependencies
   # Upgrade all root Contao requirements and resolve Contao 6 here.
   composer require juhe-it-solutions/contao-openai-assistant:^3.0 --with-all-dependencies
   ```
5. **Run `contao:migrate` and synchronise files again.** A site that ran every migration from
   the latest 2.x release should report no extension-specific schema changes. Run it anyway to
   verify the final state.

   ```bash
   php bin/console contao:migrate
   php bin/console contao:filesync
   ```

6. **Purge the Contao and page caches**, and hard-reload the backend once. Contao 6 navigates
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
configuration, prompt, file and vector-store record remains available. But 2.x does not
install on Contao 6, so a downgrade of the extension alone is not a working state - you would be
reverting the Contao upgrade too, which is a Contao-level restore from your backup, not a
Composer operation.

This is the reason for step 1 above: complete the latest 2.x upgrade and its first synchronisation
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
