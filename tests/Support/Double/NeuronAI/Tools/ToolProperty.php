<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

final class ToolProperty
{
    /**
     * @param list<string> $enum
     */
    public function __construct(
        protected string $name,
        protected PropertyType $type,
        protected ?string $description = null,
        protected bool $required = false,
        protected array $enum = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getType(): PropertyType
    {
        return $this->type;
    }

    /**
     * @return list<string>
     */
    public function getEnum(): array
    {
        return $this->enum;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
