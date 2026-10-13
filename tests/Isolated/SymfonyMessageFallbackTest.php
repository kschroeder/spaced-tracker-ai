<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tests\Isolated;

use SpacedTracker\Ai\Adapter\SymfonyAiLanguageModel;
use SpacedTracker\Ai\CompletionRequest;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SymfonyMessageFallbackTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAMessageClassWithoutFactoriesUsesTheCombinedPrompt(): void
    {
        require_once dirname(__DIR__) . '/Support/Isolated/Symfony/NoFactories/Message.php';
        require_once dirname(__DIR__) . '/Support/Double/Symfony/AI/Platform/Message/MessageBag.php';
        $this->assertCombinedPrompt();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAMessageFactoryThatReturnsNoObjectUsesTheCombinedPrompt(): void
    {
        require_once dirname(__DIR__) . '/Support/Isolated/Symfony/NullFactories/Message.php';
        require_once dirname(__DIR__) . '/Support/Double/Symfony/AI/Platform/Message/MessageBag.php';
        $this->assertCombinedPrompt();
    }

    private function assertCombinedPrompt(): void
    {
        $platform = new class {
            public string $received = '';

            public function invoke(string $model, string $input): string
            {
                unset($model);
                $this->received = $input;

                return 'combined';
            }
        };
        $result = (new SymfonyAiLanguageModel($platform))->complete(new CompletionRequest('Be brief.', 'ISS'));

        self::assertSame('combined', $result->text);
        self::assertSame("Be brief.\n\nISS", $platform->received);
    }
}
