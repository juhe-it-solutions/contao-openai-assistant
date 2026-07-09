<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Service;

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

    public function getManageUrl(): string
    {
        return $this->getProductUrl().'/manage';
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
