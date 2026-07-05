<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\EventListener;

use JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiDashboardVersionListener;
use JuheItSolutions\ContaoOpenaiAssistant\Service\BundleVersionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class OpenAiDashboardVersionListenerTest extends TestCase
{
    public function testInsertsVersionBadgeBesideModuleHeadline(): void
    {
        $listener = $this->createListener('2.1.0', 'openai_dashboard');

        $buffer = '<main id="main"><h1 id="main_headline"> <span><a href="/contao?do=openai_dashboard">OpenAI Dashboard</a></span> <span>Edit</span></h1></main>';
        $result = $listener($buffer, 'be_main');

        self::assertStringContainsString('class="oaa-bundle-version"', $result);
        self::assertStringContainsString('>v2.1.0</small>', $result);
        self::assertMatchesRegularExpression(
            '/<span><a href="[^"]+">OpenAI Dashboard<\/a><\/span> <small class="oaa-bundle-version"[^>]*>v2\.1\.0<\/small>/',
            $result,
        );
    }

    public function testInsertsVersionBadgeAfterBreadcrumbOnContao57Layout(): void
    {
        $listener = $this->createListener('2.1.0', 'openai_dashboard');

        $buffer = '<div class="content-top"><nav id="main_breadcrumb"><ol><li>OpenAI Dashboard</li></ol></nav></div>';
        $result = $listener($buffer, 'be_main');

        self::assertStringContainsString('</nav> <small class="oaa-bundle-version"', $result);
    }

    public function testSkipsOtherBackendModules(): void
    {
        $listener = $this->createListener('2.1.0', 'article');

        $buffer = '<h1 id="main_headline"><span>Articles</span></h1>';
        $result = $listener($buffer, 'be_main');

        self::assertSame($buffer, $result);
    }

    public function testSkipsOtherTemplates(): void
    {
        $listener = $this->createListener('2.1.0', 'openai_dashboard');

        $buffer = '<h1 id="main_headline"><span>OpenAI Dashboard</span></h1>';
        $result = $listener($buffer, 'be_login');

        self::assertSame($buffer, $result);
    }

    public function testLeavesBufferUntouchedWhenVersionUnavailable(): void
    {
        $listener = $this->createListener(null, 'openai_dashboard');

        $buffer = '<h1 id="main_headline"><span>OpenAI Dashboard</span></h1>';
        $result = $listener($buffer, 'be_main');

        self::assertSame($buffer, $result);
    }

    private function createListener(?string $version, string $module): OpenAiDashboardVersionListener
    {
        $bundleVersion = new class($version) extends BundleVersionService {
            public function __construct(private readonly ?string $version)
            {
            }

            public function getDisplayLabel(): ?string
            {
                if (null === $this->version) {
                    return null;
                }

                return preg_match('/^\d/', $this->version) ? 'v'.$this->version : $this->version;
            }
        };

        $requestStack = new RequestStack();
        $requestStack->push(new Request(['do' => $module]));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->with('MSC.oaa_bundle_version', [], 'contao_default')
            ->willReturn('Extension version')
        ;

        return new OpenAiDashboardVersionListener($bundleVersion, $requestStack, $translator);
    }
}
