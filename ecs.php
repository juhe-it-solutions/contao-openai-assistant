<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
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
    ])
    ->withConfiguredRule(HeaderCommentFixer::class, [
        'header' => "This file is part of Contao Open Source CMS.\n\n(c) JUHE IT-solutions\n\n@license LGPL-3.0-or-later",
    ])
    ->withParallel()
    ->withCache(sys_get_temp_dir().'/ecs/ecs')
;
