<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

use Contao\EasyCodingStandard\Fixer\CommentLengthFixer;
use Contao\EasyCodingStandard\Set\SetList;
use PhpCsFixer\Fixer\Comment\HeaderCommentFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withSets([SetList::CONTAO])
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/ecs.php',
    ])
    ->withSkip([
        __DIR__.'/vendor',
        __DIR__.'/var',
        __DIR__.'/cache',
        // The bundled CommentLengthFixer aborts with "Cannot set empty content for
        // id-based Token" on valid PHPDoc array-shape annotations (e.g. @param
        // array{...}, @return list<array{...}>). Skipping it lets ECS run; the type
        // annotations are kept because they aid PHPStan. Re-enable once the upstream
        // fixer bug is fixed.
        CommentLengthFixer::class,
        // Premium add-on files carry a proprietary header (see LICENSE-PREMIUM); the
        // HeaderCommentFixer would overwrite it with the LGPL header, i.e. rewrite a
        // licence grant.
        //
        // The directory path, NOT a glob: "src/Premium/*" left 11 of the 23 files
        // unprotected (verified by running the fixer both ways), so "ecs --fix" would
        // have relicensed them. LicenseHeaderTest asserts the outcome independently of
        // whatever this pattern happens to match.
        HeaderCommentFixer::class => [
            __DIR__.'/src/Premium',
            // Not covered by withPaths(), so a plain "ecs check" never reaches these -
            // but "ecs check tests" does, and it would stamp the LGPL header onto the
            // premium test files just the same.
            __DIR__.'/tests/Premium',
        ],
    ])
    ->withConfiguredRule(HeaderCommentFixer::class, [
        'header' => "This file is part of the JUHE Contao OpenAI Assistant bundle.\n\n(c) JUHE IT-solutions\n\n@license LGPL-3.0-or-later",
    ])
    ->withParallel()
    ->withCache(sys_get_temp_dir().'/ecs/ecs')
;
