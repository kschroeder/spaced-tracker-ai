<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tool;

use SpacedTracker\Ai\Analysis\PromptSanitizer;
use SpacedTracker\Ai\Analysis\QueryPlan;
use SpacedTracker\SpaceTrackClientInterface;

/**
 * OpenAI-style tool definition so agent libraries can call Space-Track.
 *
 * Register `schema()` with an OpenAI-compatible client. LLPhant, Neuron, Prism,
 * Symfony AI, and Laravel AI each have a bridge on {@see LibraryTools}.
 * The tool runs the query on the server. It never returns credentials.
 */
final class SpaceTrackTool
{
    public function __construct(
        private readonly SpaceTrackClientInterface $client,
        private readonly PromptSanitizer $sanitizer = new PromptSanitizer(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'space_track_query',
                'description' => 'Query the Space-Track catalog, element sets, decay messages, or public conjunctions.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'request_class' => ['type' => 'string'],
                        'controller' => ['type' => 'string'],
                        'where' => ['type' => 'object'],
                        'order_by' => ['type' => 'string'],
                        'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'limit' => ['type' => 'integer'],
                        'format' => ['type' => 'string'],
                        'rationale' => ['type' => 'string'],
                    ],
                    'required' => ['request_class', 'where', 'rationale'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function invoke(array $arguments): array
    {
        $plan = QueryPlan::fromArray($arguments);
        $query = $plan->toQuery();
        $limit = min($query->limitCount() ?? 20, 50);
        $result = $this->client->execute($query->limit($limit));

        return [
            'class' => $result->query->className(),
            'count' => $result->count(),
            'records' => $this->sanitizer->records($result->records),
        ];
    }
}
