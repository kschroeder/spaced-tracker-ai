<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;

/**
 * Adapter for neuron-core/neuron-ai.
 *
 * Instructions are set with setInstructions(). The user text is a UserMessage
 * passed to chat(). Neuron 3 returns an AgentHandler and Neuron 4 returns an
 * AgentState. Both expose getMessage().
 */
final class NeuronLanguageModel implements LanguageModel
{
    public function __construct(private readonly object $agent)
    {
    }

    public function name(): string
    {
        return 'neuron-ai';
    }

    public function complete(CompletionRequest $request): Completion
    {
        if (method_exists($this->agent, 'setInstructions')) {
            $this->agent->setInstructions($request->instructions);
        }
        if (!method_exists($this->agent, 'chat')) {
            throw new IntegrationException('Neuron agent must expose chat().');
        }

        $result = $this->agent->chat($this->userMessage($request->input));
        if (is_object($result) && method_exists($result, 'getMessage')) {
            $message = $result->getMessage();
            if (!is_string($message) && !is_object($message)) {
                throw new IntegrationException('Neuron chat returned no message.');
            }
            $result = $message;
        }

        return new Completion(TextExtractor::from($result), $this->name(), 'neuron');
    }

    private function userMessage(string $content): object
    {
        $class = OptionalSdk::className('NeuronAI', 'Chat', 'Messages', 'UserMessage');
        if (class_exists($class)) {
            return OptionalSdk::new($class, [$content]);
        }

        return new class ($content) {
            public function __construct(public string $content)
            {
            }
        };
    }
}
