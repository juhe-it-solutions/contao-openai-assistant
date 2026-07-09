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

        $this->assertNotNull($version);
        $this->assertNotSame('', $version);
    }

    public function testGetDisplayLabelPrefixesNumericVersions(): void
    {
        $service = new class() extends BundleVersionService {
            public function getVersion(): string|null
            {
                return '2.4.1';
            }
        };

        $this->assertSame('v2.4.1', $service->getDisplayLabel());
    }

    public function testGetDisplayLabelLeavesDevVersionsUntouched(): void
    {
        $service = new class() extends BundleVersionService {
            public function getVersion(): string|null
            {
                return 'dev-main';
            }
        };

        $this->assertSame('dev-main', $service->getDisplayLabel());
    }
}
