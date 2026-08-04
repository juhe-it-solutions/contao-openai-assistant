<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\EventListener;

use JuheItSolutions\ContaoOpenaiAssistant\Premium\EventListener\SearchIndexLinkListener;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LinkedFileMetadataResolver;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLink;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkExtractor;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SearchIndexLinkListenerTest extends TestCase
{
    private const HTML = '<html><body><main><a href="/kontakt.html">Kontakt</a></main></body></html>';

    /**
     * @return array{0: SearchIndexLinkListener, 1: PageLinkRepository&MockObject, 2: PageLinkExtractor&MockObject}
     */
    private function createListener(bool $enabled): array
    {
        $repository = $this->createMock(PageLinkRepository::class);
        $repository->method('isFeatureEnabled')->willReturn($enabled);
        $repository->method('siteHosts')->willReturn(['example.com']);

        $extractor = $this->createMock(PageLinkExtractor::class);

        $files = $this->createMock(LinkedFileMetadataResolver::class);
        $files->method('enrich')->willReturnArgument(0);

        return [
            new SearchIndexLinkListener($repository, $extractor, $files, new NullLogger()),
            $repository,
            $extractor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pageData(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://example.com/leistungen.html',
            'pid' => 12,
            'language' => 'de',
            'protected' => false,
        ], $overrides);
    }

    /**
     * The hook fires for EVERY page Contao indexes, including on installations
     * without the premium add-on. It must then do nothing at all.
     */
    public function testDoesNothingWhenFeatureIsDisabled(): void
    {
        [$listener, $repository, $extractor] = $this->createListener(false);

        $extractor->expects($this->never())->method('extract');
        $repository->expects($this->never())->method('replaceForSource');

        $indexData = ['text' => 'unverändert'];
        $listener(self::HTML, self::pageData(), $indexData);

        $this->assertSame(['text' => 'unverändert'], $indexData);
    }

    public function testNeverCollectsLinksOfProtectedPages(): void
    {
        [$listener, $repository, $extractor] = $this->createListener(true);

        $extractor->expects($this->never())->method('extract');
        $repository->expects($this->never())->method('replaceForSource');

        $indexData = [];
        $listener(self::HTML, self::pageData(['protected' => true]), $indexData);
    }

    public function testSkipsIncompletePageData(): void
    {
        [$listener, $repository, $extractor] = $this->createListener(true);

        $extractor->expects($this->never())->method('extract');
        $repository->expects($this->never())->method('replaceForSource');

        $indexData = [];
        $listener(self::HTML, self::pageData(['pid' => 0]), $indexData);
        $listener(self::HTML, self::pageData(['url' => '   ']), $indexData);
        $listener('   ', self::pageData(), $indexData);
    }

    public function testStoresExtractedLinksWithNormalisedSourceUrl(): void
    {
        [$listener, $repository, $extractor] = $this->createListener(true);

        $link = new PageLink('https://example.com/kontakt.html', 'Kontakt', PageLink::TYPE_PAGE);
        $extractor->method('extract')->willReturn([$link]);

        $repository
            ->expects($this->once())
            ->method('replaceForSource')
            ->with(
                12,
                'https://example.com/%C3%BCber-uns.html',
                'de',
                [$link],
            )
        ;

        $indexData = [];
        $listener(self::HTML, self::pageData(['url' => 'https://example.com/über-uns.html']), $indexData);
    }

    /**
     * A failure here must never break Contao's search indexing for the whole site.
     */
    public function testSwallowsExtractionFailures(): void
    {
        [$listener, $repository, $extractor] = $this->createListener(true);

        $extractor->method('extract')->willThrowException(new \RuntimeException('boom'));
        $repository->expects($this->never())->method('replaceForSource');

        $indexData = ['text' => 'x'];
        $listener(self::HTML, self::pageData(), $indexData);

        $this->assertSame(['text' => 'x'], $indexData);
    }

    public function testSwallowsStorageFailures(): void
    {
        [$listener, $repository, $extractor] = $this->createListener(true);

        $extractor->method('extract')->willReturn([]);
        $repository->method('replaceForSource')->willThrowException(new \RuntimeException('db down'));

        $indexData = [];
        $listener(self::HTML, self::pageData(), $indexData);

        $this->addToAssertionCount(1);
    }

    public function testLeavesTheSearchIndexRowUntouched(): void
    {
        [$listener, , $extractor] = $this->createListener(true);
        $extractor->method('extract')->willReturn([]);

        $indexData = ['text' => 'Seitentext', 'checksum' => 'abc'];
        $before = $indexData;

        $listener(self::HTML, self::pageData(), $indexData);

        $this->assertSame($before, $indexData);
    }
}
