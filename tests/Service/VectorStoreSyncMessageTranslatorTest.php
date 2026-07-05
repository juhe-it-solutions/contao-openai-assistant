<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreSyncMessageTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class VectorStoreSyncMessageTranslatorTest extends TestCase
{
    public function testExpandsPlanLimitTruncatedMessageWithSkippedAndLimit(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects(self::once())
            ->method('trans')
            ->with(
                'MSC.vsau_plan_limit_truncated',
                ['%skipped%' => '5', '%limit%' => '20'],
                'contao_default',
            )
            ->willReturn('5 pages were not synced (limit 20).')
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        self::assertSame(
            '5 pages were not synced (limit 20).',
            $service->translate('MSC.vsau_plan_limit_truncated|5|20'),
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
                        return 'Plan limit: '.$params['%skipped%'].' of over '.$params['%limit%'].' skipped.';
                    }

                    return $params['%failed%'].' uploads failed.';
                },
            )
        ;

        $service = new VectorStoreSyncMessageTranslator($translator);

        $compound = 'MSC.vsau_plan_limit_truncated|5|20'
            .VectorStoreSyncMessageTranslator::COMPOUND_SEPARATOR
            .'MSC.vsau_partial_files_failed|3';

        self::assertSame(
            'Plan limit: 5 of over 20 skipped. 3 uploads failed.',
            $service->translate($compound),
        );
    }

    public function testReturnsNullForNullMessage(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        self::assertNull((new VectorStoreSyncMessageTranslator($translator))->translate(null));
    }
}
