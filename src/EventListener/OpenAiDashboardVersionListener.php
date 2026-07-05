<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\StringUtil;
use JuheItSolutions\ContaoOpenaiAssistant\Service\BundleVersionService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Appends the installed bundle version next to the OpenAI Dashboard headline.
 */
#[AsHook('parseBackendTemplate')]
final class OpenAiDashboardVersionListener
{
    private const MODULE = 'openai_dashboard';

    public function __construct(
        private readonly BundleVersionService $bundleVersion,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(string $buffer, string $template): string
    {
        if ('be_main' !== $template || self::MODULE !== $this->getActiveModule()) {
            return $buffer;
        }

        $label = $this->bundleVersion->getDisplayLabel();

        if (null === $label) {
            return $buffer;
        }

        $badge = $this->buildVersionBadge($label);

        // Contao builds the headline with a leading space and link spans (see Backend.php).
        $updated = preg_replace(
            '/(<h1 id="main_headline">\s*)(<span>.*?<\/span>)/s',
            '$1$2'.$badge,
            $buffer,
            1,
        );

        if (\is_string($updated) && str_contains($updated, 'oaa-bundle-version')) {
            return $updated;
        }

        // Contao 5.7+ may render a breadcrumb nav instead of #main_headline on DC_Table screens.
        $updated = preg_replace(
            '/(<nav id="main_breadcrumb">.*?<\/nav>)/s',
            '$1'.$badge,
            $buffer,
            1,
        );

        return \is_string($updated) ? $updated : $buffer;
    }

    private function buildVersionBadge(string $label): string
    {
        return \sprintf(
            ' <small class="oaa-bundle-version" aria-label="%s">%s</small>',
            StringUtil::specialchars($this->translator->trans('MSC.oaa_bundle_version', [], 'contao_default')),
            StringUtil::specialchars($label),
        );
    }

    private function getActiveModule(): string|null
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        $module = $request->query->get('do');

        return \is_string($module) && '' !== $module ? $module : null;
    }
}
