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

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Keeps every sent message; throws the queued failures first, one per send() call.
 */
final class RecordingMailer implements MailerInterface
{
    /**
     * @var list<Email>
     */
    public array $sent = [];

    /**
     * @var list<\Throwable>
     */
    public array $failures = [];

    public function send(RawMessage $message, Envelope|null $envelope = null): void
    {
        if ([] !== $this->failures) {
            throw array_shift($this->failures);
        }

        \assert($message instanceof Email);
        $this->sent[] = $message;
    }
}
