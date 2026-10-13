<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tool;

/**
 * One query method registered with LLPhant, Neuron, Prism, Symfony AI, and Laravel AI.
 *
 * LLPhant calls the method whose name is the tool name. Symfony reads the AsTool
 * attribute when symfony/ai-agent is installed. The attribute class is not loaded
 * until that package reflects it.
 */
#[\Symfony\AI\Agent\Toolbox\Attribute\AsTool(
    name: self::NAME,
    description: self::DESCRIPTION,
    method: self::NAME,
)]
final class SpaceTrackQueryAction
{
    public const NAME = 'space_track_query';

    public const DESCRIPTION = 'Query the Space-Track catalog, element sets, decay messages, or public conjunctions.';

    public function __construct(private readonly SpaceTrackTool $tool)
    {
    }

    /**
     * Query the Space-Track catalog, element sets, decay messages, or public conjunctions.
     *
     * @param string $request_class Request class such as satcat, gp, decay, or tip
     * @param string $where JSON object of Space-Track predicates
     * @param string $rationale Why this query answers the user
     * @param string|null $controller Space-Track controller when the class name is not unique
     * @param string|null $order_by Field name to sort by
     * @param string|null $direction Sort direction, asc or desc
     * @param int|null $limit Maximum rows, capped by the tool
     * @param string|null $format Response format such as json, csv, or tle
     */
    public function space_track_query(
        string $request_class,
        string $where,
        string $rationale,
        ?string $controller = null,
        ?string $order_by = null,
        ?string $direction = null,
        ?int $limit = null,
        ?string $format = null,
    ): string {
        $decoded = json_decode($where, true);
        /** @var array<string, mixed> $arguments */
        $arguments = [
            'request_class' => $request_class,
            'where' => is_array($decoded) ? $decoded : [],
            'rationale' => $rationale,
        ];
        if ($controller !== null && $controller !== '') {
            $arguments['controller'] = $controller;
        }
        if ($order_by !== null && $order_by !== '') {
            $arguments['order_by'] = $order_by;
        }
        if ($direction !== null && $direction !== '') {
            $arguments['direction'] = $direction;
        }
        if ($limit !== null) {
            $arguments['limit'] = $limit;
        }
        if ($format !== null && $format !== '') {
            $arguments['format'] = $format;
        }

        return json_encode($this->tool->invoke($arguments), JSON_THROW_ON_ERROR);
    }

    public function fromArguments(
        string $request_class,
        mixed $where,
        string $rationale,
        mixed $controller = null,
        mixed $order_by = null,
        mixed $direction = null,
        mixed $limit = null,
        mixed $format = null,
    ): string {
        if (is_array($where)) {
            $encoded = json_encode($where);
            $where = is_string($encoded) ? $encoded : '{}';
        } elseif (!is_string($where) || $where === '') {
            $where = '{}';
        }

        $limitValue = null;
        if (is_int($limit)) {
            $limitValue = $limit;
        } elseif (is_float($limit) || (is_string($limit) && is_numeric($limit))) {
            $limitValue = (int) $limit;
        }

        return $this->space_track_query(
            $request_class,
            $where,
            $rationale,
            self::optionalString($controller),
            self::optionalString($order_by),
            self::optionalString($direction),
            $limitValue,
            self::optionalString($format),
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
