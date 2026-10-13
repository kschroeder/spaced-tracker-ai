<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;

/**
 * Adapter for mozex/anthropic-php and clients with the same messages()->create() shape.
 */
final class AnthropicLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly object $client,
        private readonly string $model = 'claude-sonnet-4-5',
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(CompletionRequest $request): Completion
    {
        if (!method_exists($this->client, 'messages')) {
            throw new IntegrationException('Anthropic client must expose messages()->create().');
        }
        $messages = $this->client->messages();
        if (!is_object($messages) || !method_exists($messages, 'create')) {
            throw new IntegrationException('Anthropic client must expose messages()->create().');
        }

        $raw = $messages->create([
            'model' => $request->model ?? $this->model,
            'max_tokens' => 1024,
            'system' => $request->instructions,
            'messages' => [
                ['role' => 'user', 'content' => $request->input],
            ],
        ]);

        return new Completion(TextExtractor::from($raw), $this->name(), $request->model ?? $this->model);
    }
}
