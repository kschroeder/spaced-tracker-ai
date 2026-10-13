<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Analysis;

use SpacedTracker\Ai\CompletionRequest;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\LanguageModel;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Log\RedactingLogger;
use SpacedTracker\Query\QueryResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Asks a language model to explain Space-Track results or plan a query.
 *
 * The analyst receives query results only. It has no access to the
 * Space-Track password or session cookie.
 */
final class OrbitalAnalyst
{
    public function __construct(
        private readonly LanguageModel $model,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly PromptSanitizer $sanitizer = new PromptSanitizer(),
    ) {
    }

    public function explain(QueryResult $result, ?string $question = null): Analysis
    {
        $records = $this->sanitizer->records($result->records);
        $input = json_encode([
            'class' => $result->query->className(),
            'controller' => $result->query->controller(),
            'count' => $result->count(),
            'question' => $question,
            'records' => $records,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $completion = $this->complete(
            'You explain unclassified Space-Track orbital catalog records to an operator. Be concrete, name catalog ids, and do not invent elements that are not in the data. If the data is empty, say so.',
            $input,
        );

        return new Analysis($completion->text, $completion);
    }

    public function compare(QueryResult $left, QueryResult $right, ?string $question = null): Analysis
    {
        $input = json_encode([
            'question' => $question,
            'left' => [
                'class' => $left->query->className(),
                'records' => $this->sanitizer->records($left->records),
            ],
            'right' => [
                'class' => $right->query->className(),
                'records' => $this->sanitizer->records($right->records),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $completion = $this->complete(
            'Compare these two Space-Track result sets. Mention catalog ids, orbit regime when the elements support it, and any decay or conjunction fields that are present. Do not invent missing values.',
            $input,
        );

        return new Analysis($completion->text, $completion);
    }

    public function planQuery(string $naturalLanguage): Analysis
    {
        $classes = array_map(
            static fn (array $row): string => $row['controller'] . '/' . $row['class'] . ($row['deprecated'] ? ' (deprecated)' : ''),
            RequestClass::directory(),
        );
        $instructions = <<<'PROMPT'
Turn the operator request into a Space-Track query. Reply with one JSON object and no markdown.
Use only these keys: request_class, controller, where, order_by, direction, limit, format, rationale.
where is an object of field names to predicate values. Use Space-Track operators inside values when needed: >value, <value, <>value, left--right, ~~pattern, ^prefix, null-val, now-10.
Prefer class gp for current element sets and satcat for catalog metadata. Use gp_history only when the operator asks for history.
Keep limit at 50 or below unless the operator explicitly asks for a bulk download.
PROMPT;
        $input = json_encode([
            'request' => $naturalLanguage,
            'classes' => $classes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $completion = $this->complete($instructions, $input, true);
        try {
            $plan = QueryPlan::fromArray($completion->json());
        } catch (IntegrationException $exception) {
            $this->logger->warning('AI query plan was not usable.', [
                'event' => 'spaced-tracker.ai.plan_invalid',
                'provider' => $this->model->name(),
            ]);
            throw $exception;
        }

        return new Analysis($completion->text, $completion, $plan);
    }

    private function complete(string $instructions, string $input, bool $json = false): \SpacedTracker\Ai\Completion
    {
        $logger = new RedactingLogger($this->logger);
        $logger->debug('AI analyst prompt prepared.', [
            'event' => 'spaced-tracker.ai.prompt',
            'provider' => $this->model->name(),
            'input_characters' => strlen($input),
        ]);

        return $this->model->complete(new CompletionRequest($instructions, $input, null, $json));
    }
}
