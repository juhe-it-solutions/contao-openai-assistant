<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds locale-aware URLs for the JUHE premium license portal.
 */
class LicensePortalUrlService
{
    private const BASE_URL = 'https://licenses.juhe-it-solutions.at';

    private const PRODUCT_SLUG = 'openai-assistant';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getProductUrl(): string
    {
        return self::BASE_URL.'/'.$this->resolveLocalePrefix().'/'.self::PRODUCT_SLUG;
    }

    public function getHelpUrl(): string
    {
        return $this->getProductUrl().'/help';
    }

    /**
     * Maps the active Contao backend locale to the license portal path prefix.
     */
    private function resolveLocalePrefix(): string
    {
        $locale = $this->translator->getLocale();

        return str_starts_with($locale, 'de') ? 'de' : 'en';
    }
}
