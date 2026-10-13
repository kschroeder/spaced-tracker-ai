<?php

declare(strict_types=1);

namespace SpacedTracker\Ai;

use SpacedTracker\Ai\Exception\IntegrationException;

final readonly class Completion
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public array $raw = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $text = trim($this->text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new IntegrationException('The model did not return JSON.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new IntegrationException('The model JSON must be an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
