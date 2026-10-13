<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Provider;

use SpacedTracker\Ai\Completion;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\StreamingLanguageModel;
use SpacedTracker\Log\RedactingLogger;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OpenAI-compatible HTTP client.
 *
 * SpaceXAI, OpenAI, Groq, Mistral, Together, OpenRouter, DeepSeek, and Ollama
 * all speak this protocol. SpaceXAI is the default named constructor.
 */
final class OpenAiCompatibleLanguageModel implements StreamingLanguageModel
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly string $provider,
        private readonly string $path = '/responses',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($this->apiKey === '') {
            throw new IntegrationException(sprintf('%s API key is empty.', $this->provider));
        }
    }

    public static function spaceXai(
        string $apiKey,
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        string $model = 'grok-4.7',
        string $baseUrl = 'https://api.x.ai/v1',
        ?LoggerInterface $logger = null,
    ): self {
        return new self($http, $requests, $streams, $apiKey, $model, $baseUrl, 'spacexai', '/responses', $logger ?? new NullLogger());
    }

    public static function openAi(
        string $apiKey,
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        string $model = 'gpt-4.1',
        ?LoggerInterface $logger = null,
    ): self {
        return new self($http, $requests, $streams, $apiKey, $model, 'https://api.openai.com/v1', 'openai', '/responses', $logger ?? new NullLogger());
    }

    public static function ollama(
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        string $model = 'llama3.2',
        string $baseUrl = 'http://127.0.0.1:11434/v1',
        ?LoggerInterface $logger = null,
    ): self {
        return new self($http, $requests, $streams, 'ollama', $model, $baseUrl, 'ollama', '/chat/completions', $logger ?? new NullLogger());
    }

    public static function openRouter(
        string $apiKey,
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        string $model,
        ?LoggerInterface $logger = null,
    ): self {
        return new self($http, $requests, $streams, $apiKey, $model, 'https://openrouter.ai/api/v1', 'openrouter', '/chat/completions', $logger ?? new NullLogger());
    }

    public function name(): string
    {
        return $this->provider;
    }

    public function complete(CompletionRequest $request): Completion
    {
        $payload = $this->payload($request, false);
        $raw = $this->post($this->path, $payload);
        $text = ResponseParser::text($raw);

        return new Completion($text, $this->provider, $this->modelName($request), $raw);
    }

    public function stream(CompletionRequest $request): \Generator
    {
        $payload = $this->payload($request, true);
        $body = $this->postRaw($this->path, $payload);
        $yielded = false;
        foreach (SseParser::deltas($body) as $delta) {
            $yielded = true;
            yield $delta;
        }
        if (!$yielded) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    yield ResponseParser::text($decoded);
                }
            } catch (\JsonException | IntegrationException) {
                if (trim($body) !== '') {
                    yield $body;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CompletionRequest $request, bool $stream): array
    {
        $model = $this->modelName($request);
        if (str_ends_with($this->path, '/responses')) {
            $payload = [
                'model' => $model,
                'input' => $request->combined(),
            ];
            if ($stream) {
                $payload['stream'] = true;
            }

            return $payload;
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $request->instructions],
                ['role' => 'user', 'content' => $request->input],
            ],
        ];
        if ($request->json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $body = $this->postRaw($path, $payload);
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new IntegrationException($this->provider . ' returned invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new IntegrationException($this->provider . ' returned an unexpected payload.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postRaw(string $path, array $payload): string
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = $this->requests->createRequest('POST', rtrim($this->baseUrl, '/') . $path)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($encoded));

        $started = hrtime(true);
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new IntegrationException($this->provider . ' request failed.', 0, $exception);
        }
        $duration = (hrtime(true) - $started) / 1_000_000_000;
        $body = (string) $response->getBody();
        $logger = new RedactingLogger($this->logger);
        $logger->info('AI completion finished.', [
            'event' => 'spaced-tracker.ai.complete',
            'provider' => $this->provider,
            'model' => $payload['model'] ?? $this->model,
            'status' => $response->getStatusCode(),
            'duration_seconds' => round($duration, 4),
            'input_characters' => strlen($encoded),
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new IntegrationException(sprintf('%s returned HTTP %d.', $this->provider, $response->getStatusCode()));
        }

        return $body;
    }

    private function modelName(CompletionRequest $request): string
    {
        return $request->model ?? $this->model;
    }
}
