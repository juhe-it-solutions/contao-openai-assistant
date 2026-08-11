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

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageProtectionResolver;
use PHPUnit\Framework\TestCase;

class PageProtectionResolverTest extends TestCase
{
    /**
     * The case the search index cannot answer: an editor ticks "Protect page" and Contao
     * leaves the tl_search row behind with protected=0.
     */
    public function testAProtectedPageIsResolvedFromThePageTree(): void
    {
        $resolver = new PageProtectionResolver($this->createConnection([5 => 1], [5]));

        $this->assertSame([5 => true], $resolver->protectedPageIds());
    }

    /**
     * Core semantics (PageModel::loadDetails): protection trickles down and a descendant
     * cannot switch it back off. A child of a protected page carries an empty protected flag
     * of its own and is member-only all the same.
     */
    public function testProtectionIsInheritedByEveryDescendant(): void
    {
        $tree = [
            5 => 1,   // protected parent
            6 => 5,   // child
            7 => 6,   // grandchild
            8 => 1,   // unrelated branch
        ];

        $resolver = new PageProtectionResolver($this->createConnection($tree, [5]));

        $this->assertSame(
            [5 => true, 6 => true, 7 => true],
            $resolver->protectedPageIds(),
            'Every page below the protected one is protected too - and nothing outside it is.',
        );
    }

    public function testAnUnprotectedSiteResolvesToNothing(): void
    {
        $resolver = new PageProtectionResolver($this->createConnection([5 => 1, 6 => 5], []));

        $this->assertSame([], $resolver->protectedPageIds());
    }

    /**
     * A corrupt pid chain must not spin forever. The visited set is what stops it, so a cycle
     * has to terminate on its own rather than by hitting the depth guard.
     */
    public function testACyclicPageTreeTerminates(): void
    {
        // 5 -> 6 -> 5 again.
        $resolver = new PageProtectionResolver($this->createConnection([5 => 6, 6 => 5], [5]));

        $this->assertSame([5 => true, 6 => true], $resolver->protectedPageIds());
    }

    /**
     * A lookup failure must never break a sync: an empty set only means nothing EXTRA is
     * excluded, and the caller's tl_search.protected filter still applies.
     */
    public function testALookupFailureIsNotFatal(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException(new \RuntimeException('no such table'));

        $this->assertSame([], (new PageProtectionResolver($connection))->protectedPageIds());
        $this->assertSame([], (new PageProtectionResolver($connection))->protectedUrls());
    }

    public function testProtectedUrlsAreReadForTheWholeProtectedSubtree(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchFirstColumn')
            ->willReturnCallback(
                static function (string $sql, array $params = []): array {
                    if (str_contains($sql, "protected = '1'")) {
                        return [5];
                    }

                    // Both the descendant walk and the URL lookup say "WHERE pid IN", so the
                    // table has to be part of the match.
                    if (str_contains($sql, 'FROM tl_page WHERE pid IN')) {
                        return 5 === ($params[0][0] ?? null) ? [6] : [];
                    }

                    // The URL lookup must ask for the parent AND the inherited child.
                    self::assertStringContainsString('FROM tl_search', $sql);
                    self::assertSame([5, 6], $params[0]);

                    return ['https://example.com/intern/', 'https://example.com/intern/team.html'];
                },
            )
        ;

        $this->assertSame(
            ['https://example.com/intern/', 'https://example.com/intern/team.html'],
            (new PageProtectionResolver($connection))->protectedUrls(),
        );
    }

    /**
     * @param array<int, int> $tree     page id => parent id
     * @param list<int>       $protected page ids carrying the flag themselves
     */
    private function createConnection(array $tree, array $protected): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchFirstColumn')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use ($tree, $protected): array {
                    if (str_contains($sql, "protected = '1'")) {
                        return $protected;
                    }

                    $parents = $params[0] ?? [];

                    return array_values(array_keys(array_filter(
                        $tree,
                        static fn (int $pid): bool => \in_array($pid, $parents, true),
                    )));
                },
            )
        ;

        return $connection;
    }
}
