<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreSyncMessageTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class VectorStoreSyncMessageTranslatorTest extends TestCase
{
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
