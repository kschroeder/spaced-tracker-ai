<?php

declare(strict_types=1);

namespace SpacedTracker\Ai;

final readonly class CompletionRequest
{
    public function __construct(
        public string $instructions,
        public string $input,
        public ?string $model = null,
        public bool $json = false,
    ) {
        if ($this->instructions === '' || $this->input === '') {
            throw new \InvalidArgumentException('Instructions and input are required.');
        }
    }

    public function combined(): string
    {
        return $this->instructions . "\n\n" . $this->input;
    }
}
