<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium;

use PHPUnit\Framework\TestCase;

/**
 * The package is dual-licensed (composer.json: "LGPL-3.0-or-later AND proprietary"):
 * everything under src/ is LGPL except src/Premium/, which is proprietary and covered
 * by LICENSE-PREMIUM.
 *
 * ECS enforces the LGPL header on every file it sees, and its skip list is the only
 * thing keeping it off the premium files. That skip has been wrong before - the glob
 * "src/Premium/*" left 11 of the files unprotected, so "ecs --fix" would have replaced
 * a proprietary notice with an LGPL grant. This test asserts the outcome directly, so
 * an accidental relicensing fails the build no matter what the fixer's pattern matching
 * happens to do.
 */
class LicenseHeaderTest extends TestCase
{
    private const PROPRIETARY_MARKER = '@license Proprietary - see LICENSE-PREMIUM';

    /**
     * Every proprietary source file must say so in its header.
     */
    public function testEveryPremiumSourceFileCarriesTheProprietaryHeader(): void
    {
        $files = $this->premiumFiles(__DIR__.'/../../src/Premium');

        $this->assertNotEmpty($files, 'No premium sources found - the path in this test is wrong.');

        foreach ($files as $file) {
            $this->assertStringContainsString(
                self::PROPRIETARY_MARKER,
                $this->header($file),
                \sprintf('%s lost its proprietary licence header.', $this->relative($file)),
            );
        }
    }

    /**
     * The failure mode this test exists for: an LGPL grant appearing on a proprietary
     * file. Only the file header is examined - the word may legitimately appear in code
     * or prose further down (this very file is an example).
     *
     * Scoped to src/, which is what composer ships (.gitattributes export-ignores
     * /tests). The premium tests are inconsistent about their headers today; that is a
     * separate decision, not something a build guard should force.
     */
    public function testNoPremiumSourceFileClaimsToBeLgpl(): void
    {
        foreach ($this->premiumFiles(__DIR__.'/../../src/Premium') as $file) {
            $this->assertStringNotContainsString(
                'LGPL',
                $this->header($file),
                \sprintf(
                    '%s claims an LGPL licence. Proprietary code must never carry an LGPL grant - '
                    .'this usually means "ecs --fix" ran with a skip list that missed the file.',
                    $this->relative($file),
                ),
            );
        }
    }

    /**
     * The licence header only: everything before the namespace declaration.
     */
    private function header(string $file): string
    {
        $contents = (string) file_get_contents($file);
        $end = strpos($contents, 'namespace ');

        return false === $end ? $contents : substr($contents, 0, $end);
    }

    /**
     * @return list<string>
     */
    private function premiumFiles(string $directory): array
    {
        $directory = realpath($directory);

        if (false === $directory) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        $root = realpath(__DIR__.'/../..');

        return false !== $root ? str_replace($root.'/', '', $file) : $file;
    }
}
