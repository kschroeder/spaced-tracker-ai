<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Analysis;

use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Query\Direction;
use SpacedTracker\Query\Query;

final readonly class QueryPlan
{
    /**
     * @param array<string, string> $where
     */
    public function __construct(
        public string $requestClass,
        public ?string $controller,
        public array $where,
        public ?string $orderBy,
        public ?string $direction,
        public ?int $limit,
        public string $format,
        public string $rationale,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $class = $payload['request_class'] ?? null;
        if (!is_string($class) || $class === '') {
            throw new IntegrationException('Query plan is missing request_class.');
        }
        $where = [];
        $rawWhere = $payload['where'] ?? [];
        if (is_array($rawWhere)) {
            foreach ($rawWhere as $field => $value) {
                if (is_string($field) && (is_scalar($value) || $value === null)) {
                    $where[$field] = $value === null ? 'null-val' : (string) $value;
                }
            }
        }
        $limit = $payload['limit'] ?? null;
        $direction = $payload['direction'] ?? null;

        return new self(
            $class,
            isset($payload['controller']) && is_string($payload['controller']) ? $payload['controller'] : null,
            $where,
            isset($payload['order_by']) && is_string($payload['order_by']) ? $payload['order_by'] : null,
            is_string($direction) ? $direction : null,
            is_numeric($limit) ? (int) $limit : null,
            isset($payload['format']) && is_string($payload['format']) ? $payload['format'] : 'json',
            isset($payload['rationale']) && is_string($payload['rationale']) ? $payload['rationale'] : '',
        );
    }

    public function toQuery(): Query
    {
        try {
            $class = RequestClass::resolve($this->requestClass, $this->controller);
            $query = Query::create($class);
        } catch (\InvalidArgumentException $exception) {
            if ($this->controller === null) {
                throw new IntegrationException($exception->getMessage(), 0, $exception);
            }
            $query = Query::custom($this->requestClass, $this->controller);
        }

        foreach ($this->where as $field => $value) {
            $query = $query->where($field, $value);
        }
        if ($this->orderBy !== null) {
            $direction = strtolower((string) $this->direction) === 'desc' ? Direction::Desc : Direction::Asc;
            $query = $query->orderBy($this->orderBy, $direction);
        }
        if ($this->limit !== null) {
            $query = $query->limit(max(1, $this->limit));
        }
        $format = OutputFormat::tryFrom($this->format) ?? OutputFormat::Json;

        return $query->format($format);
    }
}
