<?php

declare(strict_types=1);

namespace MonkeysLegion\DI;

final class ContainerBuilder
{
    /** @var array<string, callable|object> */
    private array $definitions = [];

    public function addDefinitions(array $defs): self
    {
        $this->definitions += $defs;
        return $this;
    }

    public function build(): Container
    {
        return new Container($this->definitions);
    }
}