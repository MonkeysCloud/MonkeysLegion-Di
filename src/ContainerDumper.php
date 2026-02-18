<?php

declare(strict_types=1);

namespace MonkeysLegion\DI;

use MonkeysLegion\DI\Exceptions\ServiceResolveException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Dump a container's definitions to a compilable PHP file.
 *
 * The generated file returns an associative array of closures that can be
 * loaded by CompiledContainer for fast production boot.
 *
 * Usage:
 *   $dumper = new ContainerDumper();
 *   $dumper->dump($container, '/path/to/compiled_container.php');
 */
final class ContainerDumper
{
    /**
     * Dump all registered definitions (their IDs) into a PHP file that
     * returns a factory-map array.
     *
     * @param Container $container  The container to dump
     * @param string    $outputPath Target file path
     *
     * @throws ServiceResolveException If the file cannot be written
     */
    public function dump(Container $container, string $outputPath): void
    {
        $definitions = $container->getDefinitions();

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * Compiled container definitions.';
        $lines[] = ' * Auto-generated — do not edit.';
        $lines[] = ' * Generated: ' . date('Y-m-d H:i:s');
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'return [';

        foreach ($definitions as $id => $definition) {
            $entry = $this->exportDefinition($id, $definition);
            if ($entry !== null) {
                $lines[] = $entry;
            }
        }

        $lines[] = '];';
        $lines[] = '';

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ServiceResolveException("Cannot create directory: {$dir}");
        }

        $content = implode("\n", $lines);
        $tmpFile = $outputPath . '.' . uniqid('', true) . '.tmp';

        if (file_put_contents($tmpFile, $content) === false) {
            throw new ServiceResolveException("Cannot write compiled container to: {$outputPath}");
        }

        // Atomic move
        if (!rename($tmpFile, $outputPath)) {
            @unlink($tmpFile);
            throw new ServiceResolveException("Cannot move compiled container from temporary file to: {$outputPath}");
        }

        // Ensure opcache picks up the new file
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($outputPath, true);
        }
    }

    /**
     * Export a single definition entry as a PHP source line for the compiled array.
     *
     * Returns null if the definition cannot be meaningfully compiled
     * (e.g. non-class alias with an unserializable closure).
     */
    private function exportDefinition(string $id, callable|object $definition): ?string
    {
        $escapedId = addslashes($id);

        // Case 1: pre-built object instance (not a callable)
        if (!is_callable($definition) && is_object($definition)) {
            // We cannot serialize arbitrary objects into PHP source.
            // Skip — the parent Container's auto-wiring will handle these.
            return null;
        }

        // Case 2: ID is a class name — generate a direct-instantiation factory.
        // Note: the original closure factory cannot be serialized to PHP source,
        // so we generate constructor auto-wiring code. This is intentional: the
        // compiled container trades custom factory logic for startup speed.
        if (class_exists($id)) {
            $factory = $this->generateClassFactory($id);
            if ($factory !== null) {
                return "    '{$escapedId}' => {$factory},";
            }
            // If we can't generate a factory, skip and let auto-wiring handle it
            return null;
        }

        // Case 3: alias with a closure factory — we can't serialize closures,
        // so we skip these. The parent container will resolve them at runtime
        // via their original definitions passed to ContainerBuilder.
        return null;
    }

    /**
     * Generate a factory closure string that directly instantiates a class.
     *
     * Inspects the constructor to resolve parameter types, producing code like:
     *   static fn(\Psr\Container\ContainerInterface $c) => new Foo($c->get(Bar::class), 42)
     */
    private function generateClassFactory(string $class): ?string
    {
        try {
            $ref = new ReflectionClass($class);

            if (!$ref->isInstantiable()) {
                return null;
            }

            $ctor = $ref->getConstructor();
            if (!$ctor) {
                return "static fn(\\Psr\\Container\\ContainerInterface \$c) => new \\{$class}()";
            }

            $args = [];
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    $args[] = "\$c->get(\\{$typeName}::class)";
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = var_export($param->getDefaultValue(), true);
                } else {
                    // Can't resolve this parameter at compile time
                    return null;
                }
            }

            $argStr = implode(', ', $args);
            return "static fn(\\Psr\\Container\\ContainerInterface \$c) => new \\{$class}({$argStr})";
        } catch (\ReflectionException) {
            return null;
        }
    }
}

