<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;

/**
 * Adapter for prism-php/prism.
 *
 * The chain is text()->using($provider, $model)->withSystemPrompt()->withPrompt()->asText().
 * The provider default is Prism's xAI provider. The URL stays in Prism config
 * so a regional XAI_URL is not overwritten. asText() exposes a public text property.
 */
final class PrismLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly object $prism,
        private readonly string $provider = 'xai',
        private readonly string $model = 'grok-4.7',
    ) {
    }

    public function name(): string
    {
        return 'prism';
    }

    public function complete(CompletionRequest $request): Completion
    {
        $model = $request->model ?? $this->model;
        if (!method_exists($this->prism, 'text')) {
            throw new IntegrationException('Prism must expose text()->using()->withSystemPrompt()->withPrompt()->asText().');
        }

        $pending = $this->prism->text();
        if (!is_object($pending) || !method_exists($pending, 'using')) {
            throw new IntegrationException('Prism must expose text()->using()->withSystemPrompt()->withPrompt()->asText().');
        }
        $used = $pending->using($this->provider, $model);
        if (is_object($used)) {
            $pending = $used;
        }
        if (!method_exists($pending, 'withSystemPrompt')) {
            throw new IntegrationException('Prism must expose text()->using()->withSystemPrompt()->withPrompt()->asText().');
        }
        $withSystem = $pending->withSystemPrompt($request->instructions);
        if (is_object($withSystem)) {
            $pending = $withSystem;
        }
        if (!method_exists($pending, 'withPrompt')) {
            throw new IntegrationException('Prism must expose text()->using()->withSystemPrompt()->withPrompt()->asText().');
        }
        $withPrompt = $pending->withPrompt($request->input);
        if (is_object($withPrompt)) {
            $pending = $withPrompt;
        }
        if (!method_exists($pending, 'asText')) {
            throw new IntegrationException('Prism must expose text()->using()->withSystemPrompt()->withPrompt()->asText().');
        }

        return new Completion(TextExtractor::from($pending->asText()), $this->name(), $model);
    }
}
