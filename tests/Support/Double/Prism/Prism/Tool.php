<?php

declare(strict_types=1);

namespace Prism\Prism;

use Closure;

final class Tool
{
    public string $name = '';

    public string $description = '';

    /** @var list<array{name: string, kind: string, required: bool}> */
    public array $defined = [];

    /** @var Closure|callable|null */
    public $handler = null;

    public function as(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function for(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function withStringParameter(string $name, string $description, bool $required = true): self
    {
        unset($description);
        $this->defined[] = ['name' => $name, 'kind' => 'string', 'required' => $required];

        return $this;
    }

    public function withNumberParameter(string $name, string $description, bool $required = true): self
    {
        unset($description);
        $this->defined[] = ['name' => $name, 'kind' => 'number', 'required' => $required];

        return $this;
    }

    /**
     * @param list<string> $options
     */
    public function withEnumParameter(string $name, string $description, array $options, bool $required = true): self
    {
        unset($description, $options);
        $this->defined[] = ['name' => $name, 'kind' => 'enum', 'required' => $required];

        return $this;
    }

    public function using(Closure|callable $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function handle(mixed ...$args): string
    {
        if (!is_callable($this->handler)) {
            throw new \RuntimeException('Prism tool has no handler.');
        }

        $result = call_user_func($this->handler, ...$args);
        if (!is_string($result)) {
            throw new \RuntimeException('Prism tool handler must return a string.');
        }

        return $result;
    }
}
