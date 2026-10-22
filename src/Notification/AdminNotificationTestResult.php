<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Notification;

/**
 * Outcome of the "Send test email" button. Each value selects the backend message
 * tl_openai_config.admin_notify_test_<value>.
 */
enum AdminNotificationTestResult: string
{
    case Sent = 'sent';
    case NoRecipients = 'no_recipients';
    case NoSender = 'no_sender';
    case TooFrequent = 'too_frequent';
    case Failed = 'failed';

    public function translationKey(): string
    {
        return 'tl_openai_config.admin_notify_test_'.$this->value;
    }
}
