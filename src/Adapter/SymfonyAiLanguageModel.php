<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;

/**
 * Adapter for symfony/ai-platform and for a symfony/ai-agent Agent.
 *
 * Platform chat input is a MessageBag of Message::forSystem() and Message::ofUser().
 * invoke() receives the model. An agent call() does not: the agent already has one.
 */
final class SymfonyAiLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly object $platform,
        private readonly string $model = 'grok-4.7',
    ) {
    }

    public function name(): string
    {
        return 'symfony-ai';
    }

    public function complete(CompletionRequest $request): Completion
    {
        $model = $request->model ?? $this->model;
        $input = $this->chatInput($request);
        if (method_exists($this->platform, 'invoke')) {
            return new Completion(TextExtractor::from($this->platform->invoke($model, $input)), $this->name(), $model);
        }
        if (method_exists($this->platform, 'call')) {
            return new Completion(TextExtractor::from($this->platform->call($input)), $this->name(), $model);
        }

        throw new IntegrationException('Symfony AI platform must expose invoke(), or an agent must expose call().');
    }

    private function chatInput(CompletionRequest $request): object|string
    {
        $messageClass = OptionalSdk::className('Symfony', 'AI', 'Platform', 'Message', 'Message');
        $bagClass = OptionalSdk::className('Symfony', 'AI', 'Platform', 'Message', 'MessageBag');
        if (!class_exists($messageClass) || !class_exists($bagClass)) {
            return $request->combined();
        }
        if (!is_callable([$messageClass, 'forSystem']) || !is_callable([$messageClass, 'ofUser'])) {
            return $request->combined();
        }

        $system = OptionalSdk::callStatic($messageClass, 'forSystem', $request->instructions);
        $user = OptionalSdk::callStatic($messageClass, 'ofUser', $request->input);
        if (!is_object($system) || !is_object($user)) {
            return $request->combined();
        }

        return OptionalSdk::new($bagClass, [$system, $user]);
    }
}
