<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;

/**
 * Adapter for openai-php/client.
 *
 * Point that client at https://api.x.ai/v1 to call SpaceXAI. responses()->create()
 * sends input as a system message and a user message, which is the xAI responses
 * shape. chat()->create() keeps the same split under messages.
 */
final class OpenAiPhpLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly object $client,
        private readonly string $model = 'grok-4.7',
        private readonly string $provider = 'openai-php',
    ) {
    }

    public function name(): string
    {
        return $this->provider;
    }

    public function complete(CompletionRequest $request): Completion
    {
        $model = $request->model ?? $this->model;
        if (method_exists($this->client, 'responses')) {
            $responses = $this->client->responses();
            if (is_object($responses) && method_exists($responses, 'create')) {
                $raw = $responses->create([
                    'model' => $model,
                    'input' => [
                        ['role' => 'system', 'content' => $request->instructions],
                        ['role' => 'user', 'content' => $request->input],
                    ],
                ]);

                return new Completion(TextExtractor::from($raw), $this->provider, $model);
            }
        }
        if (method_exists($this->client, 'chat')) {
            $chat = $this->client->chat();
            if (is_object($chat) && method_exists($chat, 'create')) {
                $raw = $chat->create([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $request->instructions],
                        ['role' => 'user', 'content' => $request->input],
                    ],
                ]);

                return new Completion(TextExtractor::from($raw), $this->provider, $model);
            }
        }

        throw new IntegrationException('openai-php/client must expose responses()->create() or chat()->create().');
    }
}
