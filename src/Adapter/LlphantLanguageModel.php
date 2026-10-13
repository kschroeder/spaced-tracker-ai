<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;

/**
 * Adapter for theodo-group/llphant.
 *
 * Chat objects get setSystemMessage() and then generateText() with the user
 * text only. Question-answering objects use answerQuestion().
 */
final class LlphantLanguageModel implements LanguageModel
{
    public function __construct(private readonly object $client)
    {
    }

    public function name(): string
    {
        return 'llphant';
    }

    public function complete(CompletionRequest $request): Completion
    {
        if (method_exists($this->client, 'setSystemMessage')) {
            $this->client->setSystemMessage($request->instructions);
        }
        if (method_exists($this->client, 'generateText')) {
            return new Completion(TextExtractor::from($this->client->generateText($request->input)), $this->name(), 'llphant');
        }
        if (method_exists($this->client, 'answerQuestion')) {
            return new Completion(TextExtractor::from($this->client->answerQuestion($request->input)), $this->name(), 'llphant');
        }
        if (method_exists($this->client, 'generate')) {
            return new Completion(TextExtractor::from($this->client->generate($request->combined())), $this->name(), 'llphant');
        }

        throw new IntegrationException('LLPhant client must expose generateText(), answerQuestion(), or generate().');
    }
}
