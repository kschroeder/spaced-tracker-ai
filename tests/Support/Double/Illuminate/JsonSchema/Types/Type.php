<?php

declare(strict_types=1);

namespace Illuminate\JsonSchema\Types;

class Type
{
    public string $description = '';

    public bool $isRequired = false;

    public bool $isNullable = false;

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function required(bool $required = true): static
    {
        $this->isRequired = $required;

        return $this;
    }

    public function nullable(bool $nullable = true): static
    {
        $this->isNullable = $nullable;

        return $this;
    }
}
