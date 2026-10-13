<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Neuron-shaped tool that can store a callable but cannot describe properties.
 */
final class Tool
{
    /** @var callable|null */
    private $callback;

    public function __construct(string $name, ?string $description = null)
    {
        unset($name, $description);
    }

    public function setCallable(callable $callback): self
    {
        $this->callback = $callback;

        return $this;
    }
}
