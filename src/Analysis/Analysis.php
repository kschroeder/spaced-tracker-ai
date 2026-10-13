<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Analysis;

use SpacedTracker\Ai\Completion;

final readonly class Analysis
{
    public function __construct(
        public string $text,
        public Completion $completion,
        public ?QueryPlan $plan = null,
    ) {
    }
}
