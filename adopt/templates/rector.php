<?php

// Managed by laravel-factory adopt.sh (created only if missing; edit freely).
// The Larastan loop runs Rector on one target file at a time as its
// deterministic actuator, before Claude is considered.

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/app'])
    ->withPhpSets()
    ->withPreparedSets(typeDeclarations: true)
    ->withComposerBased(laravel: true);
