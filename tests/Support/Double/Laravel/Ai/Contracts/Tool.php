<?php

declare(strict_types=1);

namespace Laravel\Ai\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

interface Tool
{
    public function description(): \Stringable|string;

    public function handle(Request $request): \Stringable|string;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array;
}
