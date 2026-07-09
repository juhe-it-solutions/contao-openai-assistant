<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Composer\InstalledVersions;

/**
 * Resolves the installed Composer package version of this bundle.
 */
class BundleVersionService
{
    private const PACKAGE_NAME = 'juhe-it-solutions/contao-openai-assistant';

    public function getVersion(): string|null
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        if (!InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
    }

    public function getDisplayLabel(): string|null
    {
        $version = $this->getVersion();

        if (null === $version || '' === $version) {
            return null;
        }

        return preg_match('/^\d/', $version) ? 'v'.$version : $version;
    }
}
