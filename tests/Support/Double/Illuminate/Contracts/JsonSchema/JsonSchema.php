<?php

declare(strict_types=1);

namespace Illuminate\Contracts\JsonSchema;

use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\StringType;

interface JsonSchema
{
    public function string(): StringType;

    public function integer(): IntegerType;
}
