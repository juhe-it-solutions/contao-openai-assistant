<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

declare(strict_types=1);

use Contao\DC_Table;

/*
 * Cache of AI-rewritten page documents ("KI-optimiert" indexing mode).
 *
 * Without it, every sync sends every page to the model again - the run is priced
 * per run, not per change - and because the incremental key is a hash of the
 * MODEL's output, two rewrites of an unchanged page that differ by a single
 * character also re-upload the whole site. On an hourly schedule that is a
 * standing bill for producing the same document over and over.
 *
 * A row is reused only when nothing that could change the output has changed:
 * the exact text handed to the model (source_hash) and the rewrite's own
 * parameters (fingerprint: model + system prompt). Editing the page, switching
 * the model or touching the prompt therefore all re-polish, while a quiet site
 * costs nothing after the first run.
 *
 * Only complete responses are stored - see VectorStoreAutoUpdateService, which
 * rejects a rewrite the model cut off at its output limit. Caching a truncated
 * document would make the truncation permanent.
 *
 * The table is internal machine state - created/maintained by Contao's Doctrine
 * schema sync (no migration), like tl_openai_vector_file and
 * tl_openai_page_link. The DCA exists so the schema is generated and so the
 * table is never editable; it is deliberately NOT exposed as a backend module.
 * Rows are pruned when their page leaves the sync scope.
 */
$GLOBALS['TL_DCA']['tl_openai_polish_cache'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'closed'           => true,
        'notCopyable'      => true,
        'notEditable'      => true,
        'enableVersioning' => false,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                // One cached document per page and configuration. Unique so a
                // concurrent write cannot leave two rows behind for one page -
                // the reader would then pick between them arbitrarily.
                'pid,page_id' => 'unique',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'        => 2,
            'fields'      => ['page_id'],
            'panelLayout' => 'sort,search,limit',
        ],
        'label' => [
            'fields'      => ['page_id', 'source_hash', 'tstamp'],
            'showColumns' => true,
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        // tl_openai_config.id - the cache is per configuration, like the vector store itself.
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'page_id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // sha256 of the EXACT text handed to the model, not of the page's search-index
        // checksums. The difference matters: the text is produced by BoilerplateFilter,
        // which decides what is chrome by looking at how often a block occurs ACROSS the
        // pages in scope. Adding or removing one page can therefore change what is
        // stripped from another page whose own tl_search rows never changed - and keying
        // on those checksums would serve a rewrite of text that is no longer the input.
        // Hashing the input itself makes the key correct whatever the reason for a change.
        'source_hash' => [
            'sql' => ['type' => 'string', 'length' => 64, 'fixed' => true, 'default' => ''],
        ],
        // sha256 of model + system prompt. Changing either must re-polish, otherwise a
        // corrected prompt would never reach the pages it was written to fix.
        'fingerprint' => [
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        // The model's rewritten document, exactly as it will be used. The link section is
        // NOT included: it is appended afterwards and is rebuilt from the database on every
        // run, so a link change must not be masked by a cache hit.
        'content' => [
            'sql' => ['type' => 'text', 'length' => 16777215, 'notnull' => false],
        ],
    ],
];
