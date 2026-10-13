<?php

declare(strict_types=1);

namespace SpacedTracker\Ai;

interface LanguageModel
{
    public function name(): string;

    public function complete(CompletionRequest $request): Completion;
}
