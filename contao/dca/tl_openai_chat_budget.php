<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * The per-configuration daily completion budget.
 *
 * One row per configuration per day, holding how many completions have been BOOKED - not how
 * many have finished. That distinction is the whole point of the table.
 *
 * WHY A TABLE AND NOT THE RATE LIMITER. The daily ceiling is documented as an absolute cost
 * bound that survives a distributed attack, and it used to be enforced by asking Symfony's
 * FixedWindowLimiter "is there budget left?" before the paid call and consuming a token after
 * it. Between those two points nothing was reserved, so any number of concurrent requests
 * could all see the same last token and all pay for a completion.
 *
 * Booking up front with the limiter would have been atomic but worse: a wrong API key or an
 * OpenAI outage would then burn the day's budget, letting anyone take the chatbot offline
 * until midnight with requests that never cost a cent. What is needed is reserve-and-release,
 * which Symfony's fixed-window limiter does not offer - hence one small counter we own, where
 * a single conditional UPDATE both tests and books the slot in one atomic step, and a failure
 * that provably never reached OpenAI can hand the slot back.
 *
 * Internal machine state: created and maintained by Contao's Doctrine schema sync, like
 * tl_openai_sync_log and tl_openai_vector_file. It is deliberately NOT registered as a
 * backend module - there is nothing here for an operator to edit, and the numbers are only
 * meaningful for the current day.
 */
$GLOBALS['TL_DCA']['tl_openai_chat_budget'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id'      => 'primary',
                // UNIQUE, and load-bearing: the reserve path relies on INSERT IGNORE to
                // create at most one row per configuration and day, no matter how many
                // requests race to create it.
                'pid,day' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // The UTC day as YYYYMMDD. An integer rather than a date so the daily rollover is a
        // plain equality test with no timezone handling in the hot path.
        'day' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // Completions booked today. Only ever moved by the conditional UPDATE in
        // ChatRateLimiter, never written directly.
        'spent' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
