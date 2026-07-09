<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Service\BundleVersionService;
use PHPUnit\Framework\TestCase;

class BundleVersionServiceTest extends TestCase
{
    public function testGetVersionReturnsInstalledPackageVersion(): void
    {
        $service = new BundleVersionService();
        $version = $service->getVersion();

        self::assertNotNull($version);
        self::assertNotSame('', $version);
    }

    public function testGetDisplayLabelPrefixesNumericVersions(): void
    {
        $service = new class() extends BundleVersionService {
            public function getVersion(): ?string
            {
                return '2.4.1';
            }
        };

        self::assertSame('v2.4.1', $service->getDisplayLabel());
    }

    public function testGetDisplayLabelLeavesDevVersionsUntouched(): void
    {
        $service = new class() extends BundleVersionService {
            public function getVersion(): ?string
            {
                return 'dev-main';
            }
        };

        self::assertSame('dev-main', $service->getDisplayLabel());
    }
}
