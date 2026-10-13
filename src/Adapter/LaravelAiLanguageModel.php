<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;

/**
 * Adapter for laravel/ai agents.
 *
 * Instructions stay on the agent. prompt() receives only the user text.
 * The second positional argument of prompt() is attachments, so a provider
 * or model override is passed by name. Null leaves the agent's own
 * provider and model attributes in place.
 */
final class LaravelAiLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly object $prompter,
        private readonly ?string $provider = null,
        private readonly ?string $model = null,
    ) {
    }

    public function name(): string
    {
        return 'laravel-ai';
    }

    public function complete(CompletionRequest $request): Completion
    {
        if (!method_exists($this->prompter, 'prompt')) {
            throw new IntegrationException('Laravel AI prompter must expose prompt().');
        }

        $model = $request->model ?? $this->model;
        /** @var array<string, string> $named */
        $named = [];
        if ($this->provider !== null) {
            $named['provider'] = $this->provider;
        }
        if ($model !== null) {
            $named['model'] = $model;
        }

        $raw = $named === []
            ? $this->prompter->prompt($request->input)
            : $this->prompter->prompt($request->input, ...$named);

        return new Completion(TextExtractor::from($raw), $this->name(), $model ?? 'laravel-ai');
    }
}
