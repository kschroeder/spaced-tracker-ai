<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\LanguageModel;

final class CallbackLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly mixed $callback,
        private readonly string $provider = 'callback',
    ) {
        if (!is_callable($this->callback)) {
            throw new \InvalidArgumentException('Callback language model requires a callable.');
        }
    }

    public function name(): string
    {
        return $this->provider;
    }

    public function complete(CompletionRequest $request): Completion
    {
        $callback = $this->callback;
        $text = $callback($request);
        if (!is_string($text)) {
            throw new \InvalidArgumentException('Callback language model must return a string.');
        }

        return new Completion($text, $this->provider, $request->model ?? $this->provider);
    }
}
