<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Contao6CompatibilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function backendDcaProvider(): iterable
    {
        yield 'configuration' => [__DIR__.'/../contao/dca/tl_openai_config.php'];
        yield 'files' => [__DIR__.'/../contao/dca/tl_openai_files.php'];
        yield 'prompts' => [__DIR__.'/../contao/dca/tl_openai_prompts.php'];
    }

    #[DataProvider('backendDcaProvider')]
    public function testBackendOperationsUseTheContao6ScrollOffsetTarget(string $path): void
    {
        $dca = file_get_contents($path);
        $this->assertIsString($dca);

        $this->assertStringNotContainsString('Backend.getScrollOffset()', $dca);
        $this->assertStringContainsString('data-contao--scroll-offset-target="scrollTo"', $dca);
    }

    public function testFrontendTemplateUsesTheCurrentInsertTagFilter(): void
    {
        $template = file_get_contents(__DIR__.'/../contao/templates/frontend_module/ai_chat_module.html.twig');
        $this->assertIsString($template);

        $this->assertStringNotContainsString('insert_tag_raw', $template);
        $this->assertStringContainsString('insert_tag_html', $template);
    }
}
