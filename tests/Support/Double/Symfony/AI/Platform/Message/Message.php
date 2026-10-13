<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Message;

final class Message
{
    public function __construct(
        public string $role,
        public string $text,
    ) {
    }

    public static function forSystem(string $text): self
    {
        return new self('system', $text);
    }

    public static function ofUser(string $text): self
    {
        return new self('user', $text);
    }
}
