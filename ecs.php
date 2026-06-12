<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

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
    ])
    ->withConfiguredRule(HeaderCommentFixer::class, [
        'header' => "This file is part of Contao Open Source CMS.\n\n(c) JUHE IT-solutions\n\n@license LGPL-3.0-or-later",
    ])
    ->withParallel()
    ->withCache(sys_get_temp_dir().'/ecs/ecs')
;
