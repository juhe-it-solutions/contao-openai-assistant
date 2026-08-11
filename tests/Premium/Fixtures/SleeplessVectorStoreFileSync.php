<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Fixtures;

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreFileSync;

/**
 * The real sync with its backoff ladder removed.
 *
 * The retry-exhaustion paths (429 or 500 on every attempt) are exactly the ones that used to
 * be reported as success, so they have to be tested - and testing them against the real
 * sleep() would add 31 seconds per case to every CI run.
 */
class SleeplessVectorStoreFileSync extends VectorStoreFileSync
{
    /**
     * @var list<int>
     */
    public array $sleeps = [];

    /**
     * @var list<int>
     */
    public array $pauses = [];

    protected function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
    }

    protected function pause(int $microseconds): void
    {
        $this->pauses[] = $microseconds;
    }

    /**
     * One pass through the poll loop, then the deadline is up. With the real 30 seconds and
     * a no-op pause(), a test for a file stuck "in_progress" would hammer the mock client in
     * a tight loop for half a minute.
     */
    protected function ingestWaitSeconds(): int
    {
        return 0;
    }
}

/**
 * Forces every page into two chunks so partial-upload rollback can be tested without a
 * multi-megabyte document.
 */
class TwoChunkVectorStoreFileSync extends SleeplessVectorStoreFileSync
{
    protected function splitContent(string $content): array
    {
        return ['chunk-a: '.$content, 'chunk-b: '.$content];
    }
}
