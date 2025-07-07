<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) Leo Feyer
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Security\Voter;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AiChatVoter extends Voter
{
    public function __construct()
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Request && str_starts_with($subject->getPathInfo(), '/ai-chat/');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (! $subject instanceof Request) {
            return false;
        }

        // Allow all requests to /ai-chat/ endpoints
        return true;
    }
}
