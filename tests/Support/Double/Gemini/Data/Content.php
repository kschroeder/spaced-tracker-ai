<?php

declare(strict_types=1);

namespace Gemini\Data;

final class Content
{
    public function __construct(public string $text)
    {
    }

    public static function parse(string $text): self
    {
        return new self($text);
    }
}
