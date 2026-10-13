<?php

declare(strict_types=1);

namespace SpacedTracker\Ai;

interface StreamingLanguageModel extends LanguageModel
{
    /**
     * Yield text fragments as they arrive.
     *
     * A PSR-18 client that buffers the body yields the whole answer at once.
     *
     * @return \Generator<int, string>
     */
    public function stream(CompletionRequest $request): \Generator;
}
