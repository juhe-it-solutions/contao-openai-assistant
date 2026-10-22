<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Notification;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\PageModel;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\NotificationSiteResolver;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ResolvedSite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class NotificationSiteResolverTest extends TestCase
{
    public function testARootWithADomainIsCaseA(): void
    {
        $pageFinder = $this->createMock(PageFinder::class);
        $pageFinder
            ->expects($this->once())
            ->method('findRootPageForHostAndLanguage')
            ->with('example.com')
            ->willReturn($this->mockPage(['id' => 1, 'title' => 'Main site', 'dns' => 'example.com', 'useSSL' => '1']))
        ;

        $site = (new NotificationSiteResolver($pageFinder, $this->mockFramework([])))->resolve(Request::create('https://example.com:8443/ai-chat/send'));

        $this->assertSame(ResolvedSite::CASE_VERIFIED_DOMAIN, $site->case);
        $this->assertSame('example.com', $site->host);
        $this->assertSame(1, $site->rootId);
        $this->assertSame('Main site', $site->rootTitle);
        $this->assertSame('example.com', $site->rootDns);
        $this->assertTrue($site->useSsl);
        $this->assertSame([], $site->installationRootTitles);
    }

    public function testARootWithoutADomainIsCaseB(): void
    {
        $pageFinder = $this->createMock(PageFinder::class);
        $pageFinder
            ->method('findRootPageForHostAndLanguage')
            ->willReturn($this->mockPage(['id' => 3, 'title' => 'Main site', 'dns' => '', 'useSSL' => '']))
        ;

        $site = (new NotificationSiteResolver($pageFinder, $this->mockFramework([])))->resolve(Request::create('http://example.com/ai-chat/send'));

        $this->assertSame(ResolvedSite::CASE_ROOT_WITHOUT_DOMAIN, $site->case);
        $this->assertSame('Main site', $site->rootTitle);
        $this->assertSame(3, $site->rootId);
        $this->assertNull($site->rootDns);
        $this->assertFalse($site->useSsl);
    }

    public function testNoMatchingRootIsCaseCWithTheInstallationRoots(): void
    {
        $pageFinder = $this->createMock(PageFinder::class);
        $pageFinder
            ->method('findRootPageForHostAndLanguage')
            ->willReturn(null)
        ;

        $site = (new NotificationSiteResolver($pageFinder, $this->mockFramework(['Main site', 'Shop'])))->resolve(Request::create('https://example.com/ai-chat/send'));

        $this->assertSame(ResolvedSite::CASE_NO_ROOT, $site->case);
        $this->assertSame('example.com', $site->host);
        $this->assertNull($site->rootTitle);
        $this->assertSame(['Main site', 'Shop'], $site->installationRootTitles);
        $this->assertFalse($site->moreInstallationRoots);
    }

    public function testAnInvalidHostIsCaseCWithoutAHostAndWithoutALookup(): void
    {
        $pageFinder = $this->createMock(PageFinder::class);
        $pageFinder
            ->expects($this->never())
            ->method('findRootPageForHostAndLanguage')
        ;

        $request = Request::create('/ai-chat/send');
        $request->headers->set('HOST', 'exa_mple.com');

        $site = (new NotificationSiteResolver($pageFinder, $this->mockFramework(['Main site'])))->resolve($request);

        $this->assertSame(ResolvedSite::CASE_NO_ROOT, $site->case);
        $this->assertNull($site->host);
        $this->assertSame(['Main site'], $site->installationRootTitles);
    }

    public function testAThrowingPageFinderIsCaseC(): void
    {
        $pageFinder = $this->createMock(PageFinder::class);
        $pageFinder
            ->method('findRootPageForHostAndLanguage')
            ->willThrowException(new \RuntimeException('database down'))
        ;

        $site = (new NotificationSiteResolver($pageFinder, $this->mockFramework(['Main site'])))->resolve(Request::create('https://example.com/'));

        $this->assertSame(ResolvedSite::CASE_NO_ROOT, $site->case);
        $this->assertSame('example.com', $site->host);
    }

    public function testTheRootNamesAreCappedAtTenWithAMoreFlag(): void
    {
        $pageFinder = $this->createMock(PageFinder::class);
        $titles = array_map(static fn (int $i): string => 'Site '.$i, range(1, 11));

        $site = (new NotificationSiteResolver($pageFinder, $this->mockFramework($titles)))->resolve(Request::create('https://example.com/'));

        $this->assertCount(10, $site->installationRootTitles);
        $this->assertSame('Site 10', $site->installationRootTitles[9]);
        $this->assertTrue($site->moreInstallationRoots);
    }

    public function testFailingToLoadTheRootNamesGivesAnEmptyList(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter
            ->method('__call')
            ->willThrowException(new \RuntimeException('database down'))
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework
            ->method('getAdapter')
            ->willReturn($adapter)
        ;

        $site = (new NotificationSiteResolver($this->createMock(PageFinder::class), $framework))->resolve(Request::create('https://example.com/'));

        $this->assertSame(ResolvedSite::CASE_NO_ROOT, $site->case);
        $this->assertSame([], $site->installationRootTitles);
        $this->assertFalse($site->moreInstallationRoots);
    }

    public function testControlCharactersAreStrippedFromTitles(): void
    {
        $pageFinder = $this->createMock(PageFinder::class);
        $pageFinder
            ->method('findRootPageForHostAndLanguage')
            ->willReturn($this->mockPage(['id' => 1, 'title' => "Main\r\nBcc: evil@example.org\x00", 'dns' => '']))
        ;

        $site = (new NotificationSiteResolver($pageFinder, $this->mockFramework([])))->resolve(Request::create('https://example.com/'));

        $this->assertSame('Main Bcc: evil@example.org', $site->rootTitle);
    }

    public function testSanitiseCutsAtTheLimit(): void
    {
        $this->assertSame(str_repeat('ä', 100), NotificationSiteResolver::sanitise(str_repeat('ä', 150)));
        $this->assertSame('a b c', NotificationSiteResolver::sanitise("a\tb\x7Fc"));
        $this->assertSame('abc', NotificationSiteResolver::sanitise('abcdef', 3));
        $this->assertSame('a?b', NotificationSiteResolver::sanitise("a\xFFb"));
    }

    private function mockPage(array $row): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page
            ->method('row')
            ->willReturn($row)
        ;

        return $page;
    }

    /**
     * @param list<string> $rootTitles
     */
    private function mockFramework(array $rootTitles): ContaoFramework
    {
        $pages = array_map(fn (string $title): PageModel => $this->mockPage(['title' => $title]), $rootTitles);

        $adapter = $this->createMock(Adapter::class);
        $adapter
            ->method('__call')
            ->willReturnCallback(static fn (string $method): array|null => 'findPublishedRootPages' === $method ? ($pages ?: null) : null)
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework
            ->method('getAdapter')
            ->with(PageModel::class)
            ->willReturn($adapter);

        return $framework;
    }
}
