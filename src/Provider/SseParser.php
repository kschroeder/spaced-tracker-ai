<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Provider;

final class SseParser
{
    /**
     * @return \Generator<int, string>
     */
    public static function deltas(string $payload): \Generator
    {
        foreach (preg_split("/\r\n|\n|\r/", $payload) ?: [] as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                yield $data;
                continue;
            }
            if (!is_array($decoded)) {
                continue;
            }
            $delta = ResponseParser::delta($decoded);
            if ($delta !== null && $delta !== '') {
                yield $delta;
            }
        }
    }
}
