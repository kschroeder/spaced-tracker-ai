<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

abstract class Tool
{
    protected string $name = '';

    protected ?string $description = null;

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return list<ToolProperty>
     */
    public function getProperties(): array
    {
        return $this->properties();
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public function execute(array $inputs = []): mixed
    {
        return $this->__invoke(...$inputs);
    }

    /**
     * @return list<ToolProperty>
     */
    protected function properties(): array
    {
        return [];
    }
}
