# kschroeder/spaced-tracker-ai

Adapters for PHP AI libraries, plus orbital analysis, on top of [kschroeder/spaced-tracker](https://github.com/kschroeder/spaced-tracker). The default model provider is SpaceXAI (xAI). LLPhant, Neuron, Prism, the Symfony AI SDK, the Laravel AI SDK, openai-php, and the other clients below are optional. None of them are hard dependencies, and none of them are Space-Track clients.

The adapter does not read an API key. Whoever constructs the SDK client does. Prompts and keys are not written to the log. Run tools on the server. Do not put `XAI_API_KEY` or `SPACETRACK_PASSWORD` in the browser.

Install [kschroeder/spaced-tracker-ai](https://packagist.org/packages/kschroeder/spaced-tracker-ai) from Packagist. Composer installs `kschroeder/spaced-tracker` from Packagist as well:

```bash
composer require kschroeder/spaced-tracker-ai:^1.0
```

PHP `^8.2`. Install a library only when you use that adapter. The adapters do not load a dotenv file. [`.env.example`](.env.example) lists `XAI_API_KEY`, `XAI_MODEL`, and `XAI_BASE_URL`. Pass the key into the SDK or into `OpenAiCompatibleLanguageModel`.

To work on this repository, clone it and run `composer update`. The repository has no lock file. That command clones `kschroeder/spaced-tracker` from GitHub, because this checkout's `composer.json` lists that repository. Replace that entry with a path repository of `../spaced-tracker` when Composer should symlink a neighboring checkout instead.

## LLPhant

```bash
composer require theodo-group/llphant
```

`LlphantLanguageModel` calls `setSystemMessage()` with the instructions, then `generateText()` with the user text only. A question-answering object that has `answerQuestion()` instead is called with the user text. Point the OpenAI chat client at SpaceXAI with `XAI_API_KEY`, `https://api.x.ai/v1`, and `grok-4.7`.

```php
use SpacedTracker\Ai\Adapter\LlphantLanguageModel;
use SpacedTracker\Ai\Tool\LibraryTools;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;

$config = new OpenAIConfig(
    apiKey: getenv('XAI_API_KEY') ?: '',
    url: 'https://api.x.ai/v1',
    model: 'grok-4.7',
);
$chat = new OpenAIChat($config);
$chat->setTools([LibraryTools::llphant($spaceTrackTool)]);

$model = new LlphantLanguageModel($chat);
```

`LibraryTools::llphant()` calls `FunctionBuilder::buildFunctionInfo()` on `space_track_query()`. LLPhant then calls that method by name, so the method name and the tool name are the same.

## Neuron

```bash
composer require neuron-core/neuron-ai
```

`NeuronLanguageModel` calls `setInstructions()` and then `chat()` with a `UserMessage`. Neuron 4 `chat()` returns an `AgentState`. Neuron 3 returns an `AgentHandler`. Both expose `getMessage()`, and the adapter reads that message. The completion model label stays `neuron`.

Neuron's Grok provider already uses `https://api.x.ai/v1`. Pass the key and `grok-4.7`.

```php
use SpacedTracker\Ai\Adapter\NeuronLanguageModel;
use SpacedTracker\Ai\Tool\LibraryTools;
use NeuronAI\Providers\XAI\Grok;

$grok = new Grok(
    key: getenv('XAI_API_KEY') ?: '',
    model: 'grok-4.7',
);

$model = new NeuronLanguageModel($agent);
$tool = LibraryTools::neuron($spaceTrackTool);
```

Give `$agent` the Grok provider when you construct it. `LibraryTools::neuron()` picks the tool shape from the installed major. Neuron 4's `Tool` is abstract and runs `__invoke()`. Neuron 3's `Tool` is concrete and runs `setCallable()`.

## Prism

```bash
composer require prism-php/prism
```

Prism has an `xai` provider. Its default URL is `https://api.x.ai/v1`. `XAI_URL` can override that, including a regional host. This adapter does not pass a URL into `using()`, so that config wins. The model id this package uses is `grok-4.7`.

```php
'xai' => [
    'api_key' => env('XAI_API_KEY'),
    'url' => env('XAI_URL', 'https://api.x.ai/v1'),
],
```

```php
use SpacedTracker\Ai\Adapter\PrismLanguageModel;
use SpacedTracker\Ai\Tool\LibraryTools;

$model = new PrismLanguageModel(prism(), 'xai', 'grok-4.7');
$tool = LibraryTools::prism($spaceTrackTool);
```

The call is `text()->using('xai', 'grok-4.7')->withSystemPrompt()->withPrompt()->asText()`. The text is the public `$response->text` property. `LibraryTools::prism()` constructs `new \Prism\Prism\Tool()` and calls `as()` on that instance. `Tool::as()` is not static.

## Symfony AI

```bash
composer require symfony/ai-platform
composer require symfony/ai-agent
```

`symfony/ai-platform` is the platform. `symfony/ai-agent` is the agent and the toolbox. `symfony/ai-bundle` is only the framework installer.

Point Symfony AI's generic OpenAI-compatible platform at `https://api.x.ai/v1` with the key from `XAI_API_KEY`, and use the model `grok-4.7`. `SymfonyAiLanguageModel` builds a `MessageBag` from `Message::forSystem()` and `Message::ofUser()`, then calls `invoke($model, $bag)->asText()`. An object that only exposes `call()` is treated as an agent. `call()` does not receive a model. The completion keeps the model stored on this adapter, which defaults to `grok-4.7`.

```php
use SpacedTracker\Ai\Adapter\SymfonyAiLanguageModel;
use SpacedTracker\Ai\Tool\LibraryTools;
use Symfony\AI\Agent\Toolbox\Toolbox;

$model = new SymfonyAiLanguageModel($platform, 'grok-4.7');
$action = LibraryTools::symfony($spaceTrackTool);
$toolbox = new Toolbox([$action]);
```

The action carries `#[AsTool]` for `space_track_query`. Pass the action object to `new Toolbox([$action])`.

## Laravel AI

```bash
composer require laravel/ai
```

Instructions belong to the agent, through `instructions()` or the agent's attribute. This adapter does not prepend `CompletionRequest` instructions to the prompt. `prompt()`'s second positional argument is attachments, so a provider or model override is named. Leave both null to keep the agent's `#[Provider]` and `#[Model]`.

Register an OpenAI-compatible provider named `spacexai`:

```php
'spacexai' => [
    'driver' => 'openai-compatible',
    'url' => 'https://api.x.ai/v1',
    'key' => env('XAI_API_KEY'),
],
```

```php
use SpacedTracker\Ai\Adapter\LaravelAiLanguageModel;
use SpacedTracker\Ai\Tool\LibraryTools;

$model = new LaravelAiLanguageModel($agent, 'spacexai', 'grok-4.7');
$tool = LibraryTools::laravel($spaceTrackTool);
```

That calls `prompt($input, provider: 'spacexai', model: 'grok-4.7')`. `new LaravelAiLanguageModel($agent)` calls `prompt($input)` and reports the model as `laravel-ai` until the request or the constructor names one. `LibraryTools::laravel()` returns an object that implements `Laravel\Ai\Contracts\Tool`.

## openai-php and other clients

```bash
composer require openai-php/client
```

```php
use SpacedTracker\Ai\Adapter\OpenAiPhpLanguageModel;
use OpenAI;

$client = OpenAI::factory()
    ->withApiKey(getenv('XAI_API_KEY') ?: '')
    ->withBaseUri('https://api.x.ai/v1')
    ->make();

$model = new OpenAiPhpLanguageModel($client, 'grok-4.7');
```

`responses()->create()` sends `input` as a system message and a user message. That is the xAI responses shape. A client that only has `chat()->create()` gets the same split under `messages`. Register `SpaceTrackTool::schema()` with this client. The function name is `space_track_query`.

Anthropic (`mozex/anthropic-php`) and Gemini (`google-gemini-php/client`) stay available for applications that already use them. Anthropic goes through `messages()->create()` with a separate `system` value and a user message. Gemini goes through `generativeModel()->withSystemInstruction()` and `generateContent()` of the user text. When `Gemini\Data\Content` is installed, the system text is `Content::parse()`. Their constructor model strings are placeholders for you to replace. They are not the default provider.

`CallbackLanguageModel` accepts a callable. Tests use it.

## SpaceXAI without an SDK

`OpenAiCompatibleLanguageModel` is the HTTP path when you do not have one of the libraries above. It needs a PSR-18 client and PSR-17 factories. `nyholm/psr7` plus Symfony HttpClient or Guzzle is the usual pair. The core library's `ClientBuilder` can supply that HTTP stack for Space-Track. The model client is separate so a Space-Track cookie is never attached to an AI request.

```bash
composer require kschroeder/spaced-tracker-ai nyholm/psr7 symfony/http-client
```

| Setting | Value |
| --- | --- |
| Model | `grok-4.7` |
| Base URL | `https://api.x.ai/v1` |
| Path | `/responses` |
| API key | `XAI_API_KEY` |
| Optional overrides | `XAI_MODEL`, `XAI_BASE_URL` |

```php
use SpacedTracker\Ai\Analysis\OrbitalAnalyst;
use SpacedTracker\Ai\Provider\OpenAiCompatibleLanguageModel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$factory = new Psr17Factory();
$http = new Psr18Client(HttpClient::create(), $factory, $factory);
$model = OpenAiCompatibleLanguageModel::spaceXai(
    getenv('XAI_API_KEY') ?: '',
    $http,
    $factory,
    $factory,
    getenv('XAI_MODEL') ?: 'grok-4.7',
    getenv('XAI_BASE_URL') ?: 'https://api.x.ai/v1',
);

$analyst = new OrbitalAnalyst($model);
$analysis = $analyst->explain($queryResult, 'Is this object still in orbit?');
echo $analysis->text;
```

The same class speaks any OpenAI-compatible HTTP API. Named constructors:

| Constructor | Base URL | Path | Notes |
| --- | --- | --- | --- |
| `spaceXai()` | `https://api.x.ai/v1` | `/responses` | Default. Model `grok-4.7`. |
| `openAi()` | `https://api.openai.com/v1` | `/responses` | Model argument defaults to `gpt-4.1`. Change it. |
| `openRouter()` | `https://openrouter.ai/api/v1` | `/chat/completions` | You pass the model id. |
| `ollama()` | `http://127.0.0.1:11434/v1` | `/chat/completions` | No external key. The Authorization value is the literal `ollama`. |

Groq, Mistral, DeepSeek, and Together use the same client. Construct `OpenAiCompatibleLanguageModel` with their base URL and `/chat/completions` when that is the route they document. Do not treat the placeholder model names above as a statement that they are the vendor's current default. Only the SpaceXAI model and base URL are the defaults this package promises.

Streaming is `stream(CompletionRequest)`. Server-sent `data:` lines are yielded as text fragments. `data: [DONE]` is ignored.

## Analyst

`OrbitalAnalyst` has three operations. Any `LanguageModel` can drive it, including the adapters above.

* `explain($result, $question)` describes one `QueryResult`.
* `compare($left, $right, $question)` contrasts two results.
* `planQuery($naturalLanguage)` returns a `QueryPlan`. `toQuery()` turns that plan into a core `Query`.

The plan JSON uses `request_class`, `controller`, `where`, `order_by`, `direction`, `limit`, `format`, and `rationale`. Ambiguous wire names such as `download` need `controller`. Unknown classes with a controller become `Query::custom()`.

`PromptSanitizer` runs every catalog row through the core `Redactor` before it is placed in a prompt. Password-shaped keys, cookies, and bearer tokens are replaced. At most 20 rows are sent, long strings are cut, and nested objects become `[truncated]`. The analyst does not receive the Space-Track password or the session cookie, because it never sees the client credentials. It only sees the query result you pass in.

## Tool

`SpaceTrackTool` is the query. `schema()` is the OpenAI function definition named `space_track_query`. `invoke($arguments)` runs the plan on a `SpaceTrackClientInterface` you injected. The limit is capped at 50. At most 20 redacted rows are returned. Credentials are not part of the payload.

`LibraryTools` turns that tool into the object each PHP library registers: a LLPhant `FunctionInfo`, a Neuron `Tool`, a Prism `Tool`, the Symfony action for `Toolbox`, or a Laravel AI `Tool`. `where` is a JSON object such as `{"NORAD_CAT_ID":"25544"}`. Libraries that pass the predicates as an array are encoded the same way.

```php
$tool = new SpaceTrackTool($spaceTrackClient);
$definition = $tool->schema();
$payload = $tool->invoke($argumentsFromTheModel);
```

Run the tool on the server. Do not expose `XAI_API_KEY` or `SPACETRACK_PASSWORD` to the browser.

## Logging

The HTTP provider logs `spaced-tracker.ai.complete` at info with provider, model, status, duration, and input size. It does not log the prompt or the API key. The API key constructor parameter is `#[SensitiveParameter]`, so PHP stack traces redact it. The analyst logs `spaced-tracker.ai.prompt` at debug with the character count only. Pass any PSR-3 logger. It is wrapped by `RedactingLogger`.

An empty API key throws `IntegrationException` before a request is sent. HTTP errors throw `IntegrationException` with the status code, not the provider body. A missing optional SDK throws `IntegrationException` naming the Composer package.
