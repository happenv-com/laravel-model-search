<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withSkip([
        // Rewrites a plain `=== null` check into `instanceof <FQCN>` with an inverted
        // ternary — it inlines a fully-qualified name over the imported alias and reads
        // worse than what it replaces. The other codeQuality rules stay on.
        FlipTypeControlToUseExclusiveTypeRector::class,
    ])
    // Without this, every rule that adds a type inlines a fully-qualified name instead of
    // importing it — the diffs read far worse than the code they replace.
    // removeUnusedImports stays OFF: Rector does not count docblock references, so it strips an
    // import that only a {@see Foo::bar()} tag uses and leaves the tag unresolvable. Pint's
    // no_unused_imports IS docblock-aware, so unused imports are its job, not Rector's.
    ->withImportNames(importShortClasses: false, removeUnusedImports: false)
    ->withPhpSets();
