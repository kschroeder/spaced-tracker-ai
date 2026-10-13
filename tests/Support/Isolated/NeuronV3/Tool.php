<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Neuron 3 concrete tool. Neuron 4's Tool is abstract and cannot share a process with this class.
 */
final class Tool
{
    /** @var list<ToolProperty> */
    private array $properties = [];

    /** @var callable|null */
    private $callback;

    public function __construct(
        private string $name,
        private ?string $description = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addProperty(ToolProperty $property): mixed
    {
        $this->properties[] = $property;
        if (defined('SPACED_TRACKER_NEURON_V3_VOID')) {
            return null;
        }

        return $this;
    }

    public function setCallable(callable $callback): mixed
    {
        $this->callback = $callback;
        if (defined('SPACED_TRACKER_NEURON_V3_VOID')) {
            return null;
        }

        return $this;
    }

    public function execute(): mixed
    {
        if ($this->callback === null) {
            throw new \RuntimeException('Neuron tool has no callable.');
        }
        $callback = $this->callback;

        return $callback(...func_get_args());
    }

    /**
     * @return list<ToolProperty>
     */
    public function getProperties(): array
    {
        if ($this->properties !== []) {
            return $this->properties;
        }

        return $this->properties();
    }

    /**
     * @return list<ToolProperty>
     */
    public function properties(): array
    {
        return [];
    }
}
