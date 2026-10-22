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

use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/**
 * Reads TestHandler records the same way on Monolog 2 (arrays; Contao 5.3 installs) and
 * Monolog 3 (LogRecord objects; Contao 5.7 and 6).
 */
trait CapturedLogTrait
{
    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private static function capturedRecords(TestHandler $handler): array
    {
        return array_map(
            static function (array|LogRecord $record): array {
                if ($record instanceof LogRecord) {
                    return ['level' => $record->level->getName(), 'message' => $record->message, 'context' => $record->context];
                }

                return ['level' => $record['level_name'], 'message' => $record['message'], 'context' => $record['context']];
            },
            $handler->getRecords(),
        );
    }
}
