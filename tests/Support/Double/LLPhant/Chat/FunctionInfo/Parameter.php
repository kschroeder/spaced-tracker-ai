<?php

declare(strict_types=1);

namespace LLPhant\Chat\FunctionInfo;

final class Parameter
{
    /**
     * @param list<string> $enum
     * @param array<string, mixed>|string|null $itemsOrProperties
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $description,
        public array $enum = [],
        public ?string $format = null,
        public array|string|null $itemsOrProperties = null,
    ) {
    }
}
