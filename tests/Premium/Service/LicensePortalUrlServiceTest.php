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

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicensePortalUrlService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class LicensePortalUrlServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'German' => ['de_AT', 'de'];
        yield 'German short locale' => ['de', 'de'];
        yield 'English' => ['en_US', 'en'];
        yield 'unsupported locale falls back to English' => ['fr_FR', 'en'];
    }

    #[DataProvider('localeProvider')]
    public function testBuildsCanonicalHumanUrls(string $backendLocale, string $pathLocale): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('getLocale')
            ->willReturn($backendLocale)
        ;

        $service = new LicensePortalUrlService($translator);

        $this->assertSame(
            "https://contao-chatbot.com/{$pathLocale}/",
            $service->getProductUrl(),
        );
        $this->assertSame(
            "https://contao-chatbot.com/{$pathLocale}/help",
            $service->getHelpUrl(),
        );
        $this->assertSame(
            "https://licenses.juhe-it-solutions.at/{$pathLocale}/products/contao-openai-assistant/checkout",
            $service->getCheckoutUrl(),
        );
        $this->assertSame(
            "https://licenses.juhe-it-solutions.at/{$pathLocale}/products/contao-openai-assistant/manage",
            $service->getManageUrl(),
        );
    }
}
