<?php

declare(strict_types=1);

namespace MonkeysLegion\DI;

/**
 * A compiled (cached) container that loads pre-built definitions from a PHP file.
 *
 * Falls back to the parent Container for any service not in the compiled cache.
 * Used in production to avoid repeated reflection and definition evaluation.
 */
class CompiledContainer extends Container
{
    /**
     * @param string $compiledFile Absolute path to the compiled definitions PHP file.
     *                             The file must return an associative array of callables.
     * @param array  $definitions  Additional definitions (merged after compiled ones)
     */
    public function __construct(string $compiledFile, array $definitions = [])
    {
        $compiled = [];

        if (file_exists($compiledFile)) {
            $compiled = require $compiledFile;
            if (!is_array($compiled)) {
                $compiled = [];
            }
        }

        // Merge: compiled definitions first, runtime overrides second
        parent::__construct(array_merge($compiled, $definitions));
    }
}
