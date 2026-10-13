<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;

/**
 * Adapter for google-gemini-php/client.
 *
 * When the model exposes withSystemInstruction(), the system text is a
 * Gemini\Data\Content from Content::parse() and generateContent() receives
 * only the user text.
 */
final class GeminiLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly object $client,
        private readonly string $model = 'gemini-2.5-flash',
    ) {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function complete(CompletionRequest $request): Completion
    {
        $modelName = $request->model ?? $this->model;
        if (!method_exists($this->client, 'generativeModel')) {
            throw new IntegrationException('Gemini client must expose generativeModel()->generateContent().');
        }

        $model = $this->client->generativeModel($modelName);
        if (!is_object($model) || !method_exists($model, 'generateContent')) {
            throw new IntegrationException('Gemini client must expose generativeModel()->generateContent().');
        }

        $input = $request->combined();
        if (method_exists($model, 'withSystemInstruction')) {
            $configured = $model->withSystemInstruction($this->systemInstruction($request->instructions));
            if (is_object($configured)) {
                $model = $configured;
            }
            if (!method_exists($model, 'generateContent')) {
                throw new IntegrationException('Gemini client must expose generativeModel()->generateContent().');
            }
            $input = $request->input;
        }

        return new Completion(TextExtractor::from($model->generateContent($input)), $this->name(), $modelName);
    }

    private function systemInstruction(string $instructions): mixed
    {
        $contentClass = OptionalSdk::className('Gemini', 'Data', 'Content');
        if (!class_exists($contentClass) || !is_callable([$contentClass, 'parse'])) {
            return $instructions;
        }

        $parsed = OptionalSdk::callStatic($contentClass, 'parse', $instructions);

        return is_object($parsed) ? $parsed : $instructions;
    }
}
