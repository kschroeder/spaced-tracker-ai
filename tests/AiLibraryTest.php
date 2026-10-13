<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tests;

use SpacedTracker\Ai\Adapter\AnthropicLanguageModel;
use SpacedTracker\Ai\Adapter\CallbackLanguageModel;
use SpacedTracker\Ai\Adapter\GeminiLanguageModel;
use SpacedTracker\Ai\Adapter\LaravelAiLanguageModel;
use SpacedTracker\Ai\Adapter\LlphantLanguageModel;
use SpacedTracker\Ai\Adapter\NeuronLanguageModel;
use SpacedTracker\Ai\Adapter\OpenAiPhpLanguageModel;
use SpacedTracker\Ai\Adapter\PrismLanguageModel;
use SpacedTracker\Ai\Adapter\SymfonyAiLanguageModel;
use SpacedTracker\Ai\Adapter\TextExtractor;
use SpacedTracker\Ai\Analysis\OrbitalAnalyst;
use SpacedTracker\Ai\Analysis\PromptSanitizer;
use SpacedTracker\Ai\Analysis\QueryPlan;
use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;
use SpacedTracker\Ai\Provider\OpenAiCompatibleLanguageModel;
use SpacedTracker\Ai\Provider\ResponseParser;
use SpacedTracker\Ai\Provider\SseParser;
use SpacedTracker\Ai\Tests\Support\RecordingClient;
use SpacedTracker\Ai\Tool\SpaceTrackTool;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Query\Query;
use SpacedTracker\Query\QueryResult;
use SpacedTracker\Testing\ArrayLogger;
use SpacedTracker\Testing\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AiLibraryTest extends TestCase
{
    public function testResponseParserReadsTheDocumentedTextShapes(): void
    {
        self::assertSame('alpha', ResponseParser::text(['output_text' => 'alpha', 'text' => 'other']));
        self::assertSame('beta', ResponseParser::text([
            'output' => [['content' => [['text' => 'be'], ['text' => 'ta']]]],
        ]));
        self::assertSame('kept', ResponseParser::text([
            'output' => ['skip', ['content' => [['text' => 'kept'], 'nope']]],
        ]));
        self::assertSame('chat', ResponseParser::text([
            'choices' => [['message' => ['content' => 'chat']]],
        ]));
        self::assertSame('choice-text', ResponseParser::text(['choices' => [['text' => 'choice-text']]]));
        self::assertSame('block', ResponseParser::text(['content' => [['text' => 'block']]]));
        self::assertSame('plain', ResponseParser::text(['text' => 'plain']));
        self::assertSame('d', ResponseParser::delta(['delta' => 'd']));
        self::assertSame('stream', ResponseParser::delta(['choices' => [['delta' => ['content' => 'stream']]]]));
        self::assertSame('piece', ResponseParser::delta(['type' => 'response.output_text.delta', 'delta' => 'piece']));
        self::assertNull(ResponseParser::delta(['type' => 'other']));
    }

    public function testSseParserSkipsDoneAndYieldsDeltasOrRawData(): void
    {
        $payload = implode("\n", [
            'event: message',
            'data: [DONE]',
            'data: {"delta":"one"}',
            'data: {"choices":[{"delta":{"content":"two"}}]}',
            'data: not-json',
            'data: 15',
            'data: "skip"',
            'data: null',
            'data:',
        ]);
        $deltas = iterator_to_array(SseParser::deltas($payload));

        self::assertSame(['one', 'two', 'not-json'], $deltas);
    }

    public function testSpaceXaiPostsToResponsesAndDoesNotLogTheKeyOrPrompt(): void
    {
        $http = new MockHttpClient([
            new Response(200, [], '{"output_text":"ISS is in LEO."}'),
        ]);
        $logger = new ArrayLogger();
        $factory = new Psr17Factory();
        $model = OpenAiCompatibleLanguageModel::spaceXai('xai-test-key', $http, $factory, $factory, 'grok-4.7', 'https://api.x.ai/v1', $logger);
        $completion = $model->complete(new CompletionRequest('Be brief.', 'unique-prompt-phrase-zz'));

        self::assertSame('ISS is in LEO.', $completion->text);
        self::assertSame('spacexai', $completion->provider);
        self::assertSame('grok-4.7', $completion->model);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.x.ai/v1/responses', (string) $request->getUri());
        self::assertSame('Bearer xai-test-key', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('grok-4.7', $body['model']);
        self::assertStringContainsString('unique-prompt-phrase-zz', $body['input']);
        self::assertFalse($logger->contains('xai-test-key'));
        self::assertFalse($logger->contains('unique-prompt-phrase-zz'));
        self::assertTrue($logger->contains('spaced-tracker.ai.complete'));
    }

    public function testEmptyKeyAndHttpErrorsStayFreeOfSecrets(): void
    {
        $factory = new Psr17Factory();
        $this->expectException(IntegrationException::class);
        OpenAiCompatibleLanguageModel::spaceXai('', new MockHttpClient([]), $factory, $factory);
    }

    public function testHttpFailureDoesNotIncludeTheApiKey(): void
    {
        $factory = new Psr17Factory();
        $http = new MockHttpClient([new Response(500, [], '{"error":"xai-test-key"}')]);
        $logger = new ArrayLogger();
        $model = OpenAiCompatibleLanguageModel::spaceXai('xai-test-key', $http, $factory, $factory, logger: $logger);
        try {
            $model->complete(new CompletionRequest('Be brief.', 'prompt'));
            self::fail('HTTP 500 must throw.');
        } catch (IntegrationException $exception) {
            self::assertStringNotContainsString('xai-test-key', $exception->getMessage());
            self::assertStringContainsString('500', $exception->getMessage());
        }
        self::assertFalse($logger->contains('xai-test-key'));
    }

    public function testStreamingYieldsSseDeltasAndOllamaUsesChatCompletions(): void
    {
        $factory = new Psr17Factory();
        $http = new MockHttpClient([
            new Response(200, [], "data: {\"delta\":\"A\"}\n\ndata: {\"delta\":\"B\"}\n\ndata: [DONE]\n"),
        ]);
        $model = OpenAiCompatibleLanguageModel::spaceXai('xai-test-key', $http, $factory, $factory);
        $chunks = iterator_to_array($model->stream(new CompletionRequest('Be brief.', 'stream me')));
        self::assertSame(['A', 'B'], $chunks);

        $logger = new ArrayLogger();
        $ollamaHttp = new MockHttpClient([new Response(200, [], '{"choices":[{"message":{"content":"ok"}}]}')]);
        $ollama = OpenAiCompatibleLanguageModel::ollama($ollamaHttp, $factory, $factory, 'llama3.2', 'http://127.0.0.1:11434/v1', $logger);
        $ollama->complete(new CompletionRequest('Be brief.', 'local-only-prompt'));
        self::assertSame('http://127.0.0.1:11434/v1/chat/completions', (string) $ollamaHttp->requests[0]->getUri());
        self::assertFalse($logger->contains('local-only-prompt'));
    }

    public function testQueryPlanBuildsSelectiveQueriesAndRejectsAmbiguousClasses(): void
    {
        $plan = QueryPlan::fromArray([
            'request_class' => 'gp',
            'where' => ['NORAD_CAT_ID' => '25544'],
            'order_by' => 'EPOCH',
            'direction' => 'desc',
            'limit' => 5,
            'format' => 'json',
            'rationale' => 'current elements',
        ]);
        $query = $plan->toQuery();
        self::assertSame('gp', $query->className());
        self::assertSame('basicspacedata', $query->controller());
        self::assertSame(5, $query->limitCount());
        self::assertSame('desc', $query->orders()[0]->direction->value);

        $public = QueryPlan::fromArray([
            'request_class' => 'download',
            'controller' => 'publicfiles',
            'where' => ['name' => 'demo.zip'],
            'rationale' => 'public file',
        ])->toQuery();
        self::assertSame('publicfiles', $public->controller());

        $custom = QueryPlan::fromArray([
            'request_class' => 'future_class',
            'controller' => 'basicspacedata',
            'where' => [],
            'rationale' => 'forward compatible',
        ])->toQuery();
        self::assertSame('future_class', $custom->className());

        try {
            QueryPlan::fromArray(['where' => []]);
            self::fail('A plan without request_class must be rejected.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('request_class', $exception->getMessage());
        }

        $this->expectException(IntegrationException::class);
        QueryPlan::fromArray([
            'request_class' => 'download',
            'where' => [],
            'rationale' => 'ambiguous',
        ])->toQuery();
    }

    public function testPromptSanitizerDropsPasswordsCookiesAndHugeStrings(): void
    {
        $clean = (new PromptSanitizer())->records([[
            'NORAD_CAT_ID' => 25544,
            'password' => 'correct-horse-battery',
            'note' => 'cookie=chocolatechip=session-token and Bearer abc.def',
            'blob' => str_repeat('A', 400),
            'nested' => ['secret' => 'nope'],
        ]]);

        $encoded = json_encode($clean);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('correct-horse-battery', $encoded);
        self::assertStringNotContainsString('session-token', $encoded);
        self::assertStringNotContainsString('abc.def', $encoded);
        self::assertSame('[redacted]', $clean[0]['password']);
        self::assertSame('[truncated]', $clean[0]['nested']);
        self::assertLessThan(250, strlen((string) $clean[0]['blob']));

        $indexed = (new PromptSanitizer())->records([[0 => 'skip-me', 'OBJECT_NAME' => 'ISS']]);
        self::assertSame(['OBJECT_NAME' => 'ISS'], $indexed[0]);
        $skipped = (new PromptSanitizer())->records([['OBJECT_NAME' => 'ISS'], 'not-a-row']);
        self::assertSame([['OBJECT_NAME' => 'ISS']], $skipped);
    }

    public function testAnalystPlansAndExplainsWithoutLoggingThePrompt(): void
    {
        $planJson = json_encode([
            'request_class' => 'satcat',
            'where' => ['NORAD_CAT_ID' => '25544'],
            'limit' => 1,
            'format' => 'json',
            'rationale' => 'catalog row',
        ], JSON_THROW_ON_ERROR);
        $logger = new ArrayLogger();
        $model = new CallbackLanguageModel(static fn (): string => $planJson);
        $analyst = new OrbitalAnalyst($model, $logger);
        $analysis = $analyst->planQuery('Show me the ISS catalog record');

        self::assertNotNull($analysis->plan);
        self::assertSame('satcat', $analysis->plan->toQuery()->className());
        self::assertFalse($logger->contains('Show me the ISS catalog record'));

        $explained = (new OrbitalAnalyst(new CallbackLanguageModel(static fn (): string => 'ISS remains on orbit.')))
            ->explain($this->sampleResult(RequestClass::Satcat, [['NORAD_CAT_ID' => 25544, 'OBJECT_NAME' => 'ISS (ZARYA)']]));
        self::assertSame('ISS remains on orbit.', $explained->text);
    }

    public function testAdaptersCallTheMethodsTheirLibrariesExpose(): void
    {
        $request = new CompletionRequest('Be brief.', 'ISS');
        self::assertSame('from-callback', (new CallbackLanguageModel(static fn (): string => 'from-callback'))->complete($request)->text);

        $openAi = new class {
            public function responses(): object
            {
                return new class {
                    /**
                     * @param array<string, mixed> $payload
                     */
                    public function create(array $payload): string
                    {
                        $input = $payload['input'];
                        $system = is_array($input) && ($input[0]['role'] ?? '') === 'system' ? $input[0]['content'] : 'bad-role';
                        $user = is_array($input) && ($input[1]['role'] ?? '') === 'user' ? $input[1]['content'] : 'bad-role';

                        return 'openai-php:' . $payload['model'] . ':' . $system . ':' . $user;
                    }
                };
            }
        };
        $openAiModel = new OpenAiPhpLanguageModel($openAi);
        self::assertSame('openai-php', $openAiModel->name());
        self::assertSame('openai-php:grok-4.7:Be brief.:ISS', $openAiModel->complete($request)->text);

        $chatOnly = new class {
            public function chat(): object
            {
                return new class {
                    /**
                     * @param array<string, mixed> $payload
                     */
                    public function create(array $payload): string
                    {
                        $messages = $payload['messages'];
                        $system = is_array($messages) && ($messages[0]['role'] ?? '') === 'system' ? $messages[0]['content'] : 'bad-role';
                        $user = is_array($messages) && ($messages[1]['role'] ?? '') === 'user' ? $messages[1]['content'] : 'bad-role';

                        return 'chat:' . $system . ':' . $user;
                    }
                };
            }
        };
        self::assertSame('chat:Be brief.:ISS', (new OpenAiPhpLanguageModel($chatOnly))->complete($request)->text);

        self::assertFalse(class_exists('Symfony\\AI\\Platform\\Message\\MessageBag', false));
        $platform = new class {
            public function invoke(string $model, string $input): string
            {
                return $input === "Be brief.\n\nISS" ? $model . ':symfony' : 'miss';
            }
        };
        self::assertSame('grok-4.7:symfony', (new SymfonyAiLanguageModel($platform))->complete($request)->text);

        $this->loadOptionalDouble('Symfony\\AI\\Platform\\Message\\Message', 'Symfony/AI/Platform/Message/Message.php');
        $this->loadOptionalDouble('Symfony\\AI\\Platform\\Message\\MessageBag', 'Symfony/AI/Platform/Message/MessageBag.php');
        $bagPlatform = new class {
            public function invoke(string $model, object $input): object
            {
                unset($model);

                return new class ($input) {
                    public function __construct(private object $input)
                    {
                    }

                    public function asText(): string
                    {
                        $bag = $this->input;
                        $system = $bag->messages[0]->text ?? 'miss';
                        $user = $bag->messages[1]->text ?? 'miss';

                        return $system . ':' . $user;
                    }
                };
            }
        };
        self::assertSame('Be brief.:ISS', (new SymfonyAiLanguageModel($bagPlatform))->complete($request)->text);

        $agent = new class {
            public function call(object $input): object
            {
                return new class ($input) {
                    public function __construct(private object $input)
                    {
                    }

                    public function getContent(): string
                    {
                        $bag = $this->input;

                        return $bag->messages[0]->role === 'system' && $bag->messages[1]->text === 'ISS'
                            ? 'symfony-agent'
                            : 'miss';
                    }
                };
            }
        };
        $agentResult = (new SymfonyAiLanguageModel($agent))->complete($request);
        self::assertSame('symfony-agent', $agentResult->text);
        self::assertSame('grok-4.7', $agentResult->model);

        $anthropic = new class {
            public function messages(): object
            {
                return new class {
                    /**
                     * @param array<string, mixed> $payload
                     */
                    public function create(array $payload): string
                    {
                        return 'anthropic:' . $payload['system'] . ':' . $payload['messages'][0]['content'];
                    }
                };
            }
        };
        self::assertSame('anthropic:Be brief.:ISS', (new AnthropicLanguageModel($anthropic))->complete($request)->text);

        self::assertFalse(class_exists('Gemini\\Data\\Content', false));
        $gemini = new class {
            public function generativeModel(string $model): object
            {
                return new class ($model) {
                    public function __construct(private string $model)
                    {
                    }

                    public function withSystemInstruction(string $instruction): self
                    {
                        $this->system = $instruction;

                        return $this;
                    }

                    public function generateContent(string $input): string
                    {
                        return $this->model . ':' . $this->system . ':' . $input;
                    }

                    private string $system = '';
                };
            }
        };
        self::assertSame('gemini-2.5-flash:Be brief.:ISS', (new GeminiLanguageModel($gemini))->complete($request)->text);

        $this->loadOptionalDouble('Gemini\\Data\\Content', 'Gemini/Data/Content.php');
        $geminiContent = new class {
            public function generativeModel(string $model): object
            {
                unset($model);

                return new class {
                    public function withSystemInstruction(object $instruction): self
                    {
                        $this->instructionIsContent = $instruction instanceof \Gemini\Data\Content
                            && $instruction->text === 'Be brief.';

                        return $this;
                    }

                    public function generateContent(string $input): string
                    {
                        return ($this->instructionIsContent ? 'content' : 'other') . ':' . $input;
                    }

                    private bool $instructionIsContent = false;
                };
            }
        };
        self::assertSame('content:ISS', (new GeminiLanguageModel($geminiContent))->complete($request)->text);

        $neuron = new class {
            public string $instructions = '';

            public ?object $message = null;

            public function setInstructions(string $instructions): void
            {
                $this->instructions = $instructions;
            }

            public function chat(object $message): object
            {
                $this->message = $message;

                return new class {
                    public function getMessage(): object
                    {
                        return new class {
                            public function getContent(): string
                            {
                                return 'neuron:ISS';
                            }
                        };
                    }
                };
            }
        };
        self::assertSame('neuron:ISS', (new NeuronLanguageModel($neuron))->complete($request)->text);
        self::assertSame('Be brief.', $neuron->instructions);
        $userText = '';
        if (is_object($neuron->message) && isset($neuron->message->content) && is_string($neuron->message->content)) {
            $userText = $neuron->message->content;
        } elseif (is_object($neuron->message) && method_exists($neuron->message, 'getContent')) {
            $called = $neuron->message->getContent();
            $userText = is_string($called) ? $called : '';
        }
        self::assertSame('ISS', $userText);
        $this->loadOptionalDouble('NeuronAI\\Chat\\Messages\\UserMessage', 'NeuronAI/Chat/Messages/UserMessage.php');
        (new NeuronLanguageModel($neuron))->complete($request);
        self::assertInstanceOf(\NeuronAI\Chat\Messages\UserMessage::class, $neuron->message);
        self::assertSame('ISS', $neuron->message->content);

        $nullMessage = new class {
            public function setInstructions(string $instructions): void
            {
                unset($instructions);
            }

            public function chat(object $message): object
            {
                unset($message);

                return new class {
                    public function getMessage(): ?object
                    {
                        return null;
                    }
                };
            }
        };
        try {
            (new NeuronLanguageModel($nullMessage))->complete($request);
            self::fail('A null Neuron message must throw.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('no message', $exception->getMessage());
        }

        $llphant = new class {
            public string $system = '';

            public function setSystemMessage(string $system): void
            {
                $this->system = $system;
            }

            public function generateText(string $input): string
            {
                return 'llphant:' . $input;
            }
        };
        self::assertSame('llphant:ISS', (new LlphantLanguageModel($llphant))->complete($request)->text);
        self::assertSame('Be brief.', $llphant->system);

        $qa = new class {
            public function answerQuestion(string $question): string
            {
                return 'llphant-qa:' . $question;
            }
        };
        self::assertSame('llphant-qa:ISS', (new LlphantLanguageModel($qa))->complete($request)->text);

        $legacy = new class {
            public function generate(string $prompt): string
            {
                return 'llphant-generate:' . $prompt;
            }
        };
        self::assertSame("llphant-generate:Be brief.\n\nISS", (new LlphantLanguageModel($legacy))->complete($request)->text);

        $laravel = new class {
            public string $input = '';

            public ?string $provider = null;

            public ?string $model = null;

            public function prompt(string $input, ?string $provider = null, ?string $model = null): string
            {
                $this->input = $input;
                $this->provider = $provider;
                $this->model = $model;

                return 'laravel:' . $input;
            }
        };
        $named = (new LaravelAiLanguageModel($laravel, 'spacexai', 'grok-4.7'))->complete($request);
        self::assertSame('laravel:ISS', $named->text);
        self::assertSame('grok-4.7', $named->model);
        self::assertSame('ISS', $laravel->input);
        self::assertSame('spacexai', $laravel->provider);
        self::assertSame('grok-4.7', $laravel->model);

        $plainPrompter = new class {
            public int $arguments = 0;

            public function prompt(string $input): string
            {
                $this->arguments = func_num_args();

                return 'laravel-plain:' . $input;
            }
        };
        $plain = (new LaravelAiLanguageModel($plainPrompter))->complete($request);
        self::assertSame('laravel-plain:ISS', $plain->text);
        self::assertSame('laravel-ai', $plain->model);
        self::assertSame(1, $plainPrompter->arguments);

        $override = new CompletionRequest('Be brief.', 'ISS', 'grok-4.7');
        (new LaravelAiLanguageModel($laravel))->complete($override);
        self::assertNull($laravel->provider);
        self::assertSame('grok-4.7', $laravel->model);
        self::assertSame('ISS', $laravel->input);

        $seen = new \stdClass();
        $seen->provider = '';
        $seen->model = '';
        $seen->system = '';
        $seen->prompt = '';
        $prism = new class ($seen) {
            public function __construct(private \stdClass $seen)
            {
            }

            public function text(): object
            {
                return new class ($this->seen) {
                    public function __construct(private \stdClass $seen)
                    {
                    }

                    public function using(string $provider, string $model): self
                    {
                        $this->seen->provider = $provider;
                        $this->seen->model = $model;

                        return $this;
                    }

                    public function withSystemPrompt(string $system): self
                    {
                        $this->seen->system = $system;

                        return $this;
                    }

                    public function withPrompt(string $prompt): self
                    {
                        $this->seen->prompt = $prompt;

                        return $this;
                    }

                    public function asText(): object
                    {
                        return new class ($this->seen) {
                            public string $text;

                            public function __construct(\stdClass $seen)
                            {
                                $this->text = 'prism:' . $seen->system . ':' . $seen->prompt;
                            }
                        };
                    }
                };
            }
        };
        self::assertSame('prism:Be brief.:ISS', (new PrismLanguageModel($prism))->complete($request)->text);
        self::assertSame('xai', $seen->provider);
        self::assertSame('grok-4.7', $seen->model);

        $this->expectException(IntegrationException::class);
        (new NeuronLanguageModel(new \stdClass()))->complete($request);
    }

    public function testTextExtractorReadsPropertiesAndStringableBeforeJson(): void
    {
        $property = new class {
            public string $text = 'from-property';
        };
        self::assertSame('from-property', TextExtractor::from($property));

        $content = new class {
            public string $content = 'from-content-property';
        };
        self::assertSame('from-content-property', TextExtractor::from($content));

        $stringable = new class implements \JsonSerializable, \Stringable {
            public function __toString(): string
            {
                return 'from-stringable';
            }

            /**
             * @return array<string, string>
             */
            public function jsonSerialize(): array
            {
                return ['text' => 'from-json'];
            }
        };
        self::assertSame('from-stringable', TextExtractor::from($stringable));

        $method = new class {
            public string $text = 'from-property';

            public function asText(): string
            {
                return 'from-as-text';
            }

            public function getContent(): string
            {
                return 'from-content';
            }
        };
        self::assertSame('from-as-text', TextExtractor::from($method));

        $getContent = new class {
            public string $text = 'from-property';

            public function getContent(): string
            {
                return 'from-content';
            }
        };
        self::assertSame('from-content', TextExtractor::from($getContent));

        $arrayObject = new class {
            /**
             * @return array<string, string>
             */
            public function toArray(): array
            {
                return ['output_text' => 'from-array'];
            }
        };
        self::assertSame('from-array', TextExtractor::from($arrayObject));

        try {
            TextExtractor::from(1);
            self::fail('A non-text value must throw.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('could not be read as text', $exception->getMessage());
        }
    }

    public function testTextExtractorRecursesAndRejectsValuesThatAreNotText(): void
    {
        $inner = new class {
            public string $text = 'nested-text';
        };
        $outer = new class ($inner) {
            public function __construct(private object $inner)
            {
            }

            public function asText(): object
            {
                return $this->inner;
            }
        };
        self::assertSame('nested-text', TextExtractor::from($outer));

        $fromArray = new class {
            /**
             * @return array<string, string>
             */
            public function asText(): array
            {
                return ['output_text' => 'from-method-array'];
            }
        };
        self::assertSame('from-method-array', TextExtractor::from($fromArray));

        $skipped = new class {
            public string $content = 'after-skip';

            public function asText(): int
            {
                return 1;
            }
        };
        self::assertSame('after-skip', TextExtractor::from($skipped));

        $self = new class {
            public string $text = 'after-self';

            public function asText(): self
            {
                return $this;
            }
        };
        self::assertSame('after-self', TextExtractor::from($self));

        $encoded = new class implements \JsonSerializable {
            public function toArray(): string
            {
                return 'nope';
            }

            /**
             * @return array<string, string>
             */
            public function jsonSerialize(): array
            {
                return ['text' => 'from-json'];
            }
        };
        self::assertSame('from-json', TextExtractor::from($encoded));

        $empty = new class {
        };
        try {
            TextExtractor::from($empty);
            self::fail('An empty object must not be treated as text.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('did not include text', $exception->getMessage());
        }

        $unreadable = new class implements \JsonSerializable {
            public function jsonSerialize(): string
            {
                return 'nope';
            }
        };
        try {
            TextExtractor::from($unreadable);
            self::fail('A non-array JSON value must not be treated as text.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('could not be read as text', $exception->getMessage());
        }
    }

    public function testSpaceTrackToolCapsTheLimitAndRedactsSecrets(): void
    {
        $client = new RecordingClient();
        $records = [];
        for ($index = 0; $index < 25; $index++) {
            $records[] = [
                'NORAD_CAT_ID' => $index,
                'password' => 'correct-horse-battery',
                'note' => 'chocolatechip=session-token',
            ];
        }
        $client->records = $records;
        $tool = new SpaceTrackTool($client);
        $schema = $tool->schema();
        $result = $tool->invoke([
            'request_class' => 'gp_history',
            'where' => ['NORAD_CAT_ID' => '25544'],
            'limit' => 100,
            'rationale' => 'history',
        ]);

        self::assertSame('space_track_query', $schema['function']['name']);
        self::assertSame(50, $client->queries[0]->limitCount());
        self::assertCount(20, $result['records']);
        $encoded = json_encode($result);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('correct-horse-battery', $encoded);
        self::assertStringNotContainsString('session-token', $encoded);
    }

    public function testCompareAndAnUnusablePlanStayFreeOfThePrompt(): void
    {
        $seen = new \stdClass();
        $seen->input = '';
        $model = new CallbackLanguageModel(static function (CompletionRequest $request) use ($seen): string {
            $seen->input = $request->input;

            return 'ISS is catalogued and Skylab has decayed.';
        });
        $logger = new ArrayLogger();
        $analyst = new OrbitalAnalyst($model, $logger);
        $compared = $analyst->compare(
            $this->sampleResult(RequestClass::Satcat, [[
                'NORAD_CAT_ID' => 25544,
                'OBJECT_NAME' => 'ISS (ZARYA)',
                'password' => 'correct-horse-battery',
            ]]),
            $this->sampleResult(RequestClass::Decay, [[
                'NORAD_CAT_ID' => 7530,
                'OBJECT_NAME' => 'SKYLAB',
            ]]),
            'Which object is catalogued?',
        );

        self::assertSame('ISS is catalogued and Skylab has decayed.', $compared->text);
        self::assertStringContainsString('satcat', $seen->input);
        self::assertStringContainsString('decay', $seen->input);
        self::assertStringContainsString('Which object is catalogued?', $seen->input);
        self::assertStringContainsString('25544', $seen->input);
        self::assertStringContainsString('7530', $seen->input);
        self::assertStringNotContainsString('correct-horse-battery', $seen->input);

        try {
            (new OrbitalAnalyst(new CallbackLanguageModel(static fn (): string => 'not a plan'), $logger))
                ->planQuery('Show me a secret password=correct-horse-battery');
            self::fail('A non-JSON plan must be rejected.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('did not return JSON', $exception->getMessage());
        }
        try {
            (new OrbitalAnalyst(new CallbackLanguageModel(static fn (): string => '[1]'), $logger))
                ->planQuery('list the catalog');
            self::fail('A JSON list is not a query plan.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('must be an object', $exception->getMessage());
        }
        self::assertTrue($logger->contains('spaced-tracker.ai.plan_invalid'));
        self::assertFalse($logger->contains('correct-horse-battery'));
        self::assertFalse($logger->contains('Show me a secret'));

        foreach (['' => 'input', 'instructions' => ''] as $instructions => $input) {
            try {
                new CompletionRequest($instructions, $input);
                self::fail('Empty instructions or input must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('required', $exception->getMessage());
            }
        }
    }

    public function testOpenAiCompatibleHostsRejectBadPayloadsAndFallBackWhenTheStreamIsEmpty(): void
    {
        $factory = new Psr17Factory();
        $openAiHttp = new MockHttpClient([new Response(200, [], '{"output_text":"openai"}')]);
        $openAi = OpenAiCompatibleLanguageModel::openAi('sk-test', $openAiHttp, $factory, $factory);
        $openAiResult = $openAi->complete(new CompletionRequest('Be brief.', 'prompt'));
        self::assertSame('openai', $openAi->name());
        self::assertSame('openai', $openAiResult->provider);
        self::assertSame('gpt-4.1', $openAiResult->model);
        self::assertSame('https://api.openai.com/v1/responses', (string) $openAiHttp->requests[0]->getUri());

        $routerHttp = new MockHttpClient([new Response(200, [], '{"choices":[{"message":{"content":"routed"}}]}')]);
        $router = OpenAiCompatibleLanguageModel::openRouter('or-test', $routerHttp, $factory, $factory, 'openrouter/auto');
        $routerResult = $router->complete(new CompletionRequest('Be brief.', 'prompt'));
        self::assertSame('openrouter', $router->name());
        self::assertSame('routed', $routerResult->text);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', (string) $routerHttp->requests[0]->getUri());

        $jsonStream = new MockHttpClient([new Response(200, [], "data: {\"delta\":\"x\"}\n")]);
        $ollama = OpenAiCompatibleLanguageModel::ollama($jsonStream, $factory, $factory);
        self::assertSame(
            ['x'],
            iterator_to_array($ollama->stream(new CompletionRequest('Be brief.', 'json please', json: true))),
        );
        $streamBody = json_decode((string) $jsonStream->requests[0]->getBody(), true);
        self::assertIsArray($streamBody);
        self::assertSame(['type' => 'json_object'], $streamBody['response_format']);
        self::assertTrue($streamBody['stream']);
        self::assertSame('Be brief.', $streamBody['messages'][0]['content']);
        self::assertSame('json please', $streamBody['messages'][1]['content']);

        $jsonBody = new MockHttpClient([new Response(200, [], '{"output_text":"from-json"}')]);
        $fromJson = OpenAiCompatibleLanguageModel::spaceXai('xai-test-key', $jsonBody, $factory, $factory);
        self::assertSame(
            ['from-json'],
            iterator_to_array($fromJson->stream(new CompletionRequest('Be brief.', 'stream me'))),
        );
        $rawBody = new MockHttpClient([new Response(200, [], 'plain stream body')]);
        $fromRaw = OpenAiCompatibleLanguageModel::spaceXai('xai-test-key', $rawBody, $factory, $factory);
        self::assertSame(
            ['plain stream body'],
            iterator_to_array($fromRaw->stream(new CompletionRequest('Be brief.', 'stream me'))),
        );

        $invalid = new MockHttpClient([new Response(200, [], 'not-json')]);
        try {
            OpenAiCompatibleLanguageModel::spaceXai('xai-test-key', $invalid, $factory, $factory)
                ->complete(new CompletionRequest('Be brief.', 'prompt'));
            self::fail('Invalid JSON must fail.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('invalid JSON', $exception->getMessage());
            self::assertStringNotContainsString('xai-test-key', $exception->getMessage());
        }
        $scalar = new MockHttpClient([new Response(200, [], 'null')]);
        try {
            OpenAiCompatibleLanguageModel::spaceXai('xai-test-key', $scalar, $factory, $factory)
                ->complete(new CompletionRequest('Be brief.', 'prompt'));
            self::fail('A JSON scalar must fail.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('unexpected payload', $exception->getMessage());
        }

        $broken = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ($request->getHeaderLine('Authorization')) extends \RuntimeException implements ClientExceptionInterface {
                    public function __construct(string $authorization)
                    {
                        parent::__construct('network down ' . $authorization);
                    }
                };
            }
        };
        try {
            OpenAiCompatibleLanguageModel::spaceXai('xai-test-key', $broken, $factory, $factory)
                ->complete(new CompletionRequest('Be brief.', 'prompt'));
            self::fail('A transport failure must fail.');
        } catch (IntegrationException $exception) {
            self::assertSame('spacexai request failed.', $exception->getMessage());
            self::assertStringNotContainsString('xai-test-key', $exception->getMessage());
            self::assertInstanceOf(ClientExceptionInterface::class, $exception->getPrevious());
        }
    }

    public function testAdaptersRejectClientsThatMissTheExpectedMethods(): void
    {
        $request = new CompletionRequest('Be brief.', 'ISS');

        try {
            new CallbackLanguageModel('not-callable');
            self::fail('A non-callable callback must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('callable', $exception->getMessage());
        }
        try {
            (new CallbackLanguageModel(static fn (): int => 1))->complete($request);
            self::fail('A non-string callback result must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('string', $exception->getMessage());
        }

        $this->assertIntegrationFailure(
            static fn () => (new OpenAiPhpLanguageModel(new \stdClass()))->complete($request),
            'responses()->create()',
        );
        $this->assertIntegrationFailure(
            static fn () => (new AnthropicLanguageModel(new \stdClass()))->complete($request),
            'messages()->create()',
        );
        $messages = new class {
            public function messages(): string
            {
                return 'nope';
            }
        };
        $this->assertIntegrationFailure(
            static fn () => (new AnthropicLanguageModel($messages))->complete($request),
            'messages()->create()',
        );
        $this->assertIntegrationFailure(
            static fn () => (new GeminiLanguageModel(new \stdClass()))->complete($request),
            'generateContent()',
        );
        $geminiScalar = new class {
            public function generativeModel(string $model): string
            {
                unset($model);

                return 'nope';
            }
        };
        $this->assertIntegrationFailure(
            static fn () => (new GeminiLanguageModel($geminiScalar))->complete($request),
            'generateContent()',
        );
        $geminiStripped = new class {
            public function generativeModel(string $model): object
            {
                unset($model);

                return new class {
                    public function generateContent(string $input): string
                    {
                        unset($input);

                        return 'unused';
                    }

                    public function withSystemInstruction(mixed $instruction): object
                    {
                        unset($instruction);

                        return new \stdClass();
                    }
                };
            }
        };
        $this->assertIntegrationFailure(
            static fn () => (new GeminiLanguageModel($geminiStripped))->complete($request),
            'generateContent()',
        );
        $this->assertIntegrationFailure(
            static fn () => (new LaravelAiLanguageModel(new \stdClass()))->complete($request),
            'prompt()',
        );
        $this->assertIntegrationFailure(
            static fn () => (new LlphantLanguageModel(new \stdClass()))->complete($request),
            'generateText()',
        );
        $this->assertIntegrationFailure(
            static fn () => (new SymfonyAiLanguageModel(new \stdClass()))->complete($request),
            'invoke()',
        );
        $this->assertIntegrationFailure(
            static fn () => (new PrismLanguageModel(new \stdClass()))->complete($request),
            'text()',
        );

        $prism = new class {
            public function text(): object
            {
                return new \stdClass();
            }
        };
        $this->assertIntegrationFailure(
            static fn () => (new PrismLanguageModel($prism))->complete($request),
            'using()',
        );
        $prismSystem = new class {
            public function text(): object
            {
                return new class {
                    public function using(string $provider, string $model): self
                    {
                        unset($provider, $model);

                        return $this;
                    }
                };
            }
        };
        $this->assertIntegrationFailure(
            static fn () => (new PrismLanguageModel($prismSystem))->complete($request),
            'withSystemPrompt()',
        );
        $prismPrompt = new class {
            public function text(): object
            {
                return new class {
                    public function using(string $provider, string $model): self
                    {
                        unset($provider, $model);

                        return $this;
                    }

                    public function withSystemPrompt(string $system): self
                    {
                        unset($system);

                        return $this;
                    }
                };
            }
        };
        $this->assertIntegrationFailure(
            static fn () => (new PrismLanguageModel($prismPrompt))->complete($request),
            'withPrompt()',
        );
        $prismText = new class {
            public function text(): object
            {
                return new class {
                    public function using(string $provider, string $model): self
                    {
                        unset($provider, $model);

                        return $this;
                    }

                    public function withSystemPrompt(string $system): self
                    {
                        unset($system);

                        return $this;
                    }

                    public function withPrompt(string $prompt): self
                    {
                        unset($prompt);

                        return $this;
                    }
                };
            }
        };
        $this->assertIntegrationFailure(
            static fn () => (new PrismLanguageModel($prismText))->complete($request),
            'asText()',
        );
    }

    private function assertIntegrationFailure(callable $call, string $needle): void
    {
        try {
            $call();
            self::fail('The adapter must reject a client that cannot ' . $needle);
        } catch (IntegrationException $exception) {
            self::assertStringContainsString($needle, $exception->getMessage());
        }
    }

    private function loadOptionalDouble(string $class, string $path): void
    {
        if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
            return;
        }

        require_once __DIR__ . '/Support/Double/' . $path;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function sampleResult(RequestClass $class, array $records): QueryResult
    {
        return new QueryResult(Query::create($class), 200, '[]', $records, null, 0.0, []);
    }
}
