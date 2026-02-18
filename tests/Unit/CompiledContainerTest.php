<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Tests\Unit;

use MonkeysLegion\DI\CompiledContainer;
use MonkeysLegion\DI\Container;
use MonkeysLegion\DI\ContainerDumper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CompiledContainerTest extends TestCase
{
    private string $tmpDir;
    private string $compiledFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/di_compiled_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->compiledFile = $this->tmpDir . '/compiled_container.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->compiledFile)) {
            unlink($this->compiledFile);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function compiled_container_loads_definitions_from_file(): void
    {
        // Write a simple compiled file
        file_put_contents($this->compiledFile, '<?php return [
            "greeting" => fn() => "hello world",
        ];');

        $c = new CompiledContainer($this->compiledFile);
        $this->assertSame('hello world', $c->get('greeting'));
    }

    #[Test]
    public function compiled_container_falls_back_to_auto_wiring(): void
    {
        // Empty compiled file
        file_put_contents($this->compiledFile, '<?php return [];');

        $c = new CompiledContainer($this->compiledFile);
        $obj = $c->get(StubNoConstructor::class);
        $this->assertInstanceOf(StubNoConstructor::class, $obj);
    }

    #[Test]
    public function compiled_container_merges_runtime_definitions(): void
    {
        file_put_contents($this->compiledFile, '<?php return [
            "compiled" => fn() => "from_cache",
        ];');

        $c = new CompiledContainer($this->compiledFile, [
            'runtime' => fn() => 'from_runtime',
        ]);

        $this->assertSame('from_cache', $c->get('compiled'));
        $this->assertSame('from_runtime', $c->get('runtime'));
    }

    #[Test]
    public function compiled_container_runtime_overrides_compiled(): void
    {
        file_put_contents($this->compiledFile, '<?php return [
            "service" => fn() => "compiled",
        ];');

        $c = new CompiledContainer($this->compiledFile, [
            'service' => fn() => 'overridden',
        ]);

        $this->assertSame('overridden', $c->get('service'));
    }

    #[Test]
    public function compiled_container_handles_missing_file(): void
    {
        $c = new CompiledContainer('/nonexistent/path/file.php');
        $obj = $c->get(StubNoConstructor::class);
        $this->assertInstanceOf(StubNoConstructor::class, $obj);
    }

    #[Test]
    public function compiled_container_extends_container(): void
    {
        $c = new CompiledContainer($this->compiledFile);
        $this->assertInstanceOf(Container::class, $c);
    }

    /* -----------------------------------------------------------------
     *  ContainerDumper
     * --------------------------------------------------------------- */

    #[Test]
    public function dumper_creates_file(): void
    {
        $c = new Container([
            StubNoConstructor::class => fn() => new StubNoConstructor(),
        ]);

        $dumper = new ContainerDumper();
        $dumper->dump($c, $this->compiledFile);

        $this->assertFileExists($this->compiledFile);
    }

    #[Test]
    public function dumped_file_is_valid_php(): void
    {
        $c = new Container([
            StubNoConstructor::class => fn() => new StubNoConstructor(),
            'alias.service' => fn() => 'hello',
        ]);

        $dumper = new ContainerDumper();
        $dumper->dump($c, $this->compiledFile);

        $result = require $this->compiledFile;
        $this->assertIsArray($result);
        // Class-based definitions are compiled with direct instantiation
        $this->assertArrayHasKey(StubNoConstructor::class, $result);
        // Non-class alias closures are NOT compiled (closures can't be serialized)
        // They are resolved at runtime by the parent Container's original definitions
        $this->assertArrayNotHasKey('alias.service', $result);
    }

    #[Test]
    public function dump_then_load_round_trip(): void
    {
        $original = new Container([
            StubNoConstructor::class => fn() => new StubNoConstructor(),
        ]);

        $dumper = new ContainerDumper();
        $dumper->dump($original, $this->compiledFile);

        $compiled = new CompiledContainer($this->compiledFile);
        $obj = $compiled->get(StubNoConstructor::class);
        $this->assertInstanceOf(StubNoConstructor::class, $obj);
    }

    #[Test]
    public function dumper_creates_directory_if_needed(): void
    {
        $nestedDir = $this->tmpDir . '/nested/deep';
        $nestedFile = $nestedDir . '/compiled.php';

        $c = new Container(['x' => fn() => 'test']);
        $dumper = new ContainerDumper();
        $dumper->dump($c, $nestedFile);

        $this->assertFileExists($nestedFile);

        // Cleanup nested
        unlink($nestedFile);
        rmdir($nestedDir);
        rmdir($this->tmpDir . '/nested');
    }
}
