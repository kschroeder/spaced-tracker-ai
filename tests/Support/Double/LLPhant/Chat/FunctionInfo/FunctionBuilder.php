<?php

declare(strict_types=1);

namespace LLPhant\Chat\FunctionInfo;

final class FunctionBuilder
{
    public static function buildFunctionInfo(object $instance, string $name): FunctionInfo
    {
        return new FunctionInfo($name, $instance, 'built', [], []);
    }
}
