<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

/**
 * Stand-in for Neuron's user message when the package is not installed.
 */
final class UserMessage
{
    public function __construct(public string $content)
    {
    }
}
