<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Neuron-shaped tool that never grew setCallable().
 */
final class Tool
{
    public function __construct(string $name, ?string $description = null)
    {
        unset($name, $description);
    }
}
