<?php

declare(strict_types=1);

namespace LLPhant\Chat\FunctionInfo;

/**
 * A FunctionBuilder that does not return a FunctionInfo.
 *
 * The real test double always returns one, so this file can load only in its own process.
 */
final class FunctionBuilder
{
    public static function buildFunctionInfo(object $instance, string $name): mixed
    {
        unset($instance, $name);

        return null;
    }
}
