<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
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
    public function testInsertsVersionBadgeInsideModuleTitleLinkOnLegacyHeadline(): void
    {
        $listener = $this->createListener('2.1.0', 'openai_dashboard');

        $buffer = '<main id="main"><h1 id="main_headline"> <span><a href="/contao?do=openai_dashboard">OpenAI Dashboard</a></span> <span>Edit</span></h1></main>';
        $result = $listener($buffer, 'be_main');

        self::assertStringContainsString('class="oaa-bundle-version"', $result);
        self::assertStringContainsString('>v2.1.0</span>', $result);
        self::assertMatchesRegularExpression(
            '/<a href="[^"]+">OpenAI Dashboard <span class="oaa-bundle-version"[^>]*>v2\.1\.0<\/span><\/a>/',
            $result,
        );
    }

    public function testInsertsVersionBadgeInsideModuleTitleLinkOnContao57Breadcrumb(): void
    {
        $listener = $this->createListener('2.1.0', 'openai_dashboard');

        $buffer = '<div class="content-top"><nav id="main_breadcrumb"><ul><li class="current"><a href="/contao?do=openai_dashboard">OpenAI Dashboard</a></li></ul></nav></div>';
        $result = $listener($buffer, 'be_main');

        self::assertMatchesRegularExpression(
            '/<a href="\/contao\?do=openai_dashboard">OpenAI Dashboard <span class="oaa-bundle-version"[^>]*>v2\.1\.0<\/span><\/a>/',
            $result,
        );
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
