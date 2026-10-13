<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\Provider\ResponseParser;

final class TextExtractor
{
    public static function from(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return ResponseParser::text($value);
        }
        if (!is_object($value)) {
            throw new IntegrationException('The AI library returned a value that could not be read as text.');
        }

        foreach (['asText', 'text', 'getContent'] as $method) {
            if (!method_exists($value, $method)) {
                continue;
            }
            $called = $value->{$method}();
            if (is_string($called)) {
                return $called;
            }
            if ($called !== $value && (is_array($called) || is_object($called))) {
                return self::from($called);
            }
        }

        $public = get_object_vars($value);
        if (isset($public['text']) && is_string($public['text'])) {
            return $public['text'];
        }
        if (isset($public['content']) && is_string($public['content'])) {
            return $public['content'];
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (method_exists($value, 'toArray')) {
            $array = $value->toArray();
            if (is_array($array)) {
                /** @var array<string, mixed> $array */
                return ResponseParser::text($array);
            }
        }

        $encoded = json_encode($value);
        if (is_string($encoded)) {
            $decoded = json_decode($encoded, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return ResponseParser::text($decoded);
            }
        }

        throw new IntegrationException('The AI library returned a value that could not be read as text.');
    }
}
