<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Message;

/**
 * Partial Symfony AI message class: the factories do not return messages.
 */
final class Message
{
    public static function forSystem(string $text): mixed
    {
        unset($text);

        return null;
    }

    public static function ofUser(string $text): mixed
    {
        unset($text);

        return null;
    }
}
