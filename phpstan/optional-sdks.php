<?php

declare(strict_types=1);

/**
 * Symbols for optional PHP AI libraries that are not installed with this package.
 *
 * PHPStan discovers classes through scanFiles. Stub files only override PHPDocs
 * on symbols that already exist, so they cannot introduce these SDKs.
 * This file is not autoloaded and is never executed at runtime.
 */

namespace NeuronAI\Tools {
    abstract class Tool
    {
        protected string $name;

        protected ?string $description = null;

        public function getName(): string
        {
            return $this->name;
        }

        public function getDescription(): ?string
        {
            return $this->description;
        }

        /**
         * @return list<ToolProperty>
         */
        public function getProperties(): array
        {
            return $this->properties();
        }

        /**
         * @return list<ToolProperty>
         */
        protected function properties(): array
        {
            return [];
        }
    }

    class ToolProperty
    {
    }
}

namespace Laravel\Ai\Tools {
    /**
     * @implements \ArrayAccess<string, mixed>
     */
    class Request implements \ArrayAccess
    {
        public function offsetExists(mixed $offset): bool
        {
            return false;
        }

        public function offsetGet(mixed $offset): mixed
        {
            return null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    }
}

namespace Laravel\Ai\Contracts {
    interface Tool
    {
        public function description(): \Stringable|string;

        public function handle(\Laravel\Ai\Tools\Request $request): \Stringable|string;

        /**
         * @return array<string, mixed>
         */
        public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array;
    }
}

namespace Illuminate\JsonSchema\Types {
    class Type
    {
        public function description(string $description): static
        {
            return $this;
        }

        public function required(bool $required = true): static
        {
            return $this;
        }

        public function nullable(bool $nullable = true): static
        {
            return $this;
        }
    }

    class StringType extends Type
    {
    }

    class IntegerType extends Type
    {
    }
}

namespace Illuminate\Contracts\JsonSchema {
    interface JsonSchema
    {
        /**
         * @param array<string, mixed>|\Closure $properties
         */
        public function object(\Closure|array $properties = []): \Illuminate\JsonSchema\Types\Type;

        public function array(): \Illuminate\JsonSchema\Types\Type;

        public function string(): \Illuminate\JsonSchema\Types\StringType;

        public function integer(): \Illuminate\JsonSchema\Types\IntegerType;

        public function number(): \Illuminate\JsonSchema\Types\Type;

        public function boolean(): \Illuminate\JsonSchema\Types\Type;

        /**
         * @param array<int, string> $types
         */
        public function union(array $types): \Illuminate\JsonSchema\Types\Type;

        /**
         * @param array<int, mixed>|\Closure $schemas
         */
        public function anyOf(\Closure|array $schemas): \Illuminate\JsonSchema\Types\Type;
    }
}

namespace Symfony\AI\Agent\Toolbox\Attribute {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    final class AsTool
    {
        /**
         * @param array<string, mixed> $metadata
         */
        public function __construct(
            public string $name,
            public string $description,
            public string $method = '__invoke',
            public array $metadata = [],
        ) {
        }
    }
}
