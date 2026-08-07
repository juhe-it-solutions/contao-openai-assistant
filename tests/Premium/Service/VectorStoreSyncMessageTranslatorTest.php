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

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreSyncMessageTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class VectorStoreSyncMessageTranslatorTest extends TestCase
{
    /**
     * The one crawl failure that is not about the site: Symfony rebuilt the compiled
     * container while the crawl was running and deleted the directory it was still
     * requiring service files from. Dumping that PHP output into a backend table tells an
     * operator nothing, so the cause is named instead.
     */
    public function testNamesTheCauseWhenTheContainerCacheWasRebuiltMidCrawl(): void
    {
        $error = <<<'ERR'
            21:04:19 WARNING [php] Warning: require(/var/www/site/var/cache/prod/ContainerXE6omHe/getFosHttpCache_EventListener_InvalidationService.php): Failed to open stream: No such file or directory
            In Contao_ManagerBundle_HttpKernel_ContaoKernelProdContainer.php line 720:
            Failed opening required '/var/www/site/var/cache/prod/ContainerXE6omHe/getFosHttpCache_EventListener_InvalidationService.php'
            ERR;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with('MSC.vsau_err_crawl_cache_rebuilt', [], 'contao_default')
            ->willReturn('The server rebuilt its cache during the crawl. Start the synchronisation again.')
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        $this->assertSame(
            'The server rebuilt its cache during the crawl. Start the synchronisation again.',
            $service->translate('MSC.vsau_err_crawl_failed|'.$error),
        );
    }

    /**
     * Legacy rows stored the failure as plain English text; they must be recognised too.
     */
    public function testRecognisesTheCacheRebuildInALegacyStoredMessage(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static fn (string $key): string => $key)
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        $this->assertSame(
            'MSC.vsau_err_crawl_cache_rebuilt',
            $service->translate(
                "contao:crawl failed: Failed opening required '/srv/web/var/cache/prod/ContainerAbc123/getSomeService.php'",
            ),
        );
    }

    /**
     * A real crawl problem must keep reaching the operator verbatim - the cache-rebuild
     * branch must not swallow anything that merely mentions a missing file.
     */
    public function testAnOrdinaryCrawlFailureIsStillReportedInFull(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static fn (string $key, array $args): string => $key.'|'.($args[0] ?? ''))
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        // Missing file, but not a compiled-container path.
        $this->assertSame(
            'MSC.vsau_err_crawl_failed|Failed to open stream: No such file or directory in /srv/web/templates/foo.html5',
            $service->translate('MSC.vsau_err_crawl_failed|Failed to open stream: No such file or directory in /srv/web/templates/foo.html5'),
        );

        // A container path, but no missing-file error - a different problem entirely.
        $this->assertSame(
            'MSC.vsau_err_crawl_failed|Connection refused while loading /var/cache/prod/ContainerAbc/x.php',
            $service->translate('MSC.vsau_err_crawl_failed|Connection refused while loading /var/cache/prod/ContainerAbc/x.php'),
        );
    }

    public function testExpandsPlanLimitTruncatedMessageWithSkippedAndLimit(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with(
                'MSC.vsau_plan_limit_truncated',
                ['20', '5'],
                'contao_default',
            )
            ->willReturn('5 pages were not synced (limit 20).')
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        $this->assertSame(
            '5 pages were not synced (limit 20).',
            $service->translate('MSC.vsau_plan_limit_truncated|5|20'),
        );
    }

    public function testExpandsTruncationMessageThatAlsoLostReaderItems(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with(
                'MSC.vsau_plan_limit_truncated_items',
                ['20', '1', '300'],
                'contao_default',
            )
            ->willReturn('1 page (300 entries) was not synced (limit 20).')
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        $this->assertSame(
            '1 page (300 entries) was not synced (limit 20).',
            $service->translate('MSC.vsau_plan_limit_truncated_items|1|20|300'),
        );
    }

    public function testExpandsItemBudgetExceededMessage(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with(
                'MSC.vsau_plan_item_limit_exceeded',
                ['50', '63'],
                'contao_default',
            )
            ->willReturn('63 entries present, plan allows 50.')
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        $this->assertSame(
            '63 entries present, plan allows 50.',
            $service->translate('MSC.vsau_plan_item_limit_exceeded|63|50'),
        );
    }

    public function testAppendsTheCrawlerSummaryToANothingIndexedError(): void
    {
        // The summary is free text straight from contao:crawl and routinely contains
        // "|", so it must not be parsed like the numeric keyed messages.
        $summary = 'search-index | [OK] Indexed 0 URI(s) successfully. 0 failed. | [WARNING] 1 URI(s) were skipped.';

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(
                static function (string $id, array $params = []) use ($summary): string {
                    if ('MSC.vsau_err_selected_not_indexed' === $id) {
                        return 'None of the selected pages were found.';
                    }

                    if ('MSC.vsau_crawl_result' === $id) {
                        self::assertSame([$summary], $params, 'The whole summary must survive, pipes included.');

                        return 'Result of the crawl: '.$params[0];
                    }

                    return $id;
                },
            )
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        $this->assertSame(
            'None of the selected pages were found. Result of the crawl: '.$summary,
            $service->translate(
                'MSC.vsau_err_selected_not_indexed'
                .VectorStoreSyncMessageTranslator::COMPOUND_SEPARATOR
                .'MSC.vsau_crawl_result|'.$summary,
            ),
        );
    }

    public function testExpandsCompoundMessageWithBothReasons(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(
                static function (string $id, array $params): string {
                    if ('MSC.vsau_plan_limit_truncated' === $id) {
                        return 'Plan limit: '.$params[1].' of over '.$params[0].' skipped.';
                    }

                    return $params[0].' uploads failed.';
                },
            )
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        $compound = 'MSC.vsau_plan_limit_truncated|5|20'
            .VectorStoreSyncMessageTranslator::COMPOUND_SEPARATOR
            .'MSC.vsau_partial_files_failed|3';

        $this->assertSame(
            'Plan limit: 5 of over 20 skipped. 3 uploads failed.',
            $service->translate($compound),
        );
    }

    public function testReturnsNullForNullMessage(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->never())
            ->method('trans')
        ;

        $this->assertNull((new VectorStoreSyncMessageTranslator($translator))->translate(null));
    }
}
