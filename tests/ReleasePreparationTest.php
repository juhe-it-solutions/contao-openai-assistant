<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests;

use PHPUnit\Framework\TestCase;

class ReleasePreparationTest extends TestCase
{
    public function testComposerMetadataUsesStableContao6Dependencies(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('^8.4', $composer['require']['php'] ?? null);
        $this->assertSame('^6.0', $composer['require']['contao/core-bundle'] ?? null);
        $this->assertArrayNotHasKey('minimum-stability', $composer);
    }

    public function testReleaseWorkflowUsesTheV3PlatformAndTagPinnedLinks(): void
    {
        $workflow = file_get_contents(__DIR__.'/../.github/workflows/release.yml');
        $this->assertIsString($workflow);

        $this->assertStringContainsString('id: lane', $workflow);
        $this->assertStringContainsString('v2.*)', $workflow);
        $this->assertStringContainsString('echo "php=8.2"', $workflow);
        $this->assertStringContainsString('echo "contao=5.3.*"', $workflow);
        $this->assertStringContainsString('v3.*)', $workflow);
        $this->assertStringContainsString('echo "php=8.4"', $workflow);
        $this->assertStringContainsString('echo "contao=^6.0"', $workflow);
        $this->assertStringContainsString('php-version: ${{ steps.lane.outputs.php }}', $workflow);
        $this->assertStringContainsString('contao/core-bundle:"${{ steps.lane.outputs.contao }}"', $workflow);
        $this->assertStringContainsString('"$GITHUB_REPOSITORY" "$GITHUB_REF_NAME"', $workflow);
        $this->assertStringContainsString('blob/$GITHUB_REF_NAME/CHANGELOG.md', $workflow);
        $this->assertStringNotContainsString('tree/$GITHUB_REF_NAME/docs)', $workflow);
    }

    public function testReleaseScriptChecksV3WithoutCreatingATag(): void
    {
        $script = file_get_contents(__DIR__.'/../scripts/release.sh');
        $this->assertIsString($script);

        $this->assertStringContainsString('--check', $script);
        $this->assertStringContainsString("RELEASE_BRANCH='main'", $script);
        $this->assertStringContainsString("RELEASE_BRANCH='2.x'", $script);
        $this->assertStringContainsString('origin/$RELEASE_BRANCH', $script);
        $this->assertStringContainsString('scripts/check-release-archive.sh', $script);
        $this->assertStringContainsString('CONTAO_CONSTRAINT=\'^6.0\'', $script);
        $this->assertStringContainsString('PHP 8.4', $script);
        $this->assertStringContainsString('## [Unreleased] - $VERSION', $script);
        $this->assertStringContainsString('date it before tagging', $script);
    }

    public function testContao6VerifierIncludesNewUntrackedSourceFiles(): void
    {
        $script = file_get_contents(__DIR__.'/../scripts/verify-contao6.sh');
        $this->assertIsString($script);

        $this->assertStringContainsString('--cached --others --exclude-standard', $script);
        $this->assertStringContainsString('mktemp -d "$WORKSPACE_ROOT/app.XXXXXX"', $script);
        $this->assertStringNotContainsString('.vendor-cache', $script);
    }
}
