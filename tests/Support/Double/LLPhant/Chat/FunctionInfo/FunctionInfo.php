<?php

declare(strict_types=1);

namespace LLPhant\Chat\FunctionInfo;

final class FunctionInfo
{
    /**
     * @param list<mixed> $parameters
     * @param list<mixed> $requiredParameters
     */
    public function __construct(
        public string $name,
        public mixed $instance,
        public string $description,
        public array $parameters,
        public array $requiredParameters = [],
    ) {
    }
}
