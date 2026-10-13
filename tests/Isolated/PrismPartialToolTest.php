<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tests\Isolated;

use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\Tests\Support\RecordingClient;
use SpacedTracker\Ai\Tool\LibraryTools;
use SpacedTracker\Ai\Tool\SpaceTrackTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PrismPartialToolTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function gaps(): array
    {
        return [
            ['missing-as', 'as().'],
            ['null-as', 'as().'],
            ['missing-for', 'for().'],
            ['missing-withStringParameter', 'withStringParameter().'],
            ['null-withStringParameter', 'parameter methods must return the tool.'],
            ['missing-withEnumParameter', 'withEnumParameter().'],
            ['missing-withNumberParameter', 'withNumberParameter().'],
            ['missing-using', 'using().'],
            ['null-using', 'using().'],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('gaps')]
    public function testAPartialPrismToolIsRejected(string $gap, string $message): void
    {
        $path = tempnam(sys_get_temp_dir(), 'prism-tool-');
        self::assertIsString($path);
        file_put_contents($path, $this->toolSource($gap));
        try {
            require $path;
            LibraryTools::prism(new SpaceTrackTool(new RecordingClient()));
            self::fail('A partial Prism tool must be rejected.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    private function toolSource(string $gap): string
    {
        $methods = [
            'as' => 'string $value',
            'for' => 'string $value',
            'withStringParameter' => 'string $name, string $description, bool $required = true',
            'withEnumParameter' => 'string $name, string $description, array $enum, bool $required = true',
            'withNumberParameter' => 'string $name, string $description, bool $required = true',
            'using' => 'callable $handler',
        ];
        $body = '';
        foreach ($methods as $name => $parameters) {
            if ($gap === 'missing-' . $name) {
                continue;
            }
            $return = $gap === 'null-' . $name ? 'null' : '$this';
            $body .= "    public function {$name}({$parameters}): mixed\n    {\n        return {$return};\n    }\n\n";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Prism\\Prism;\n\nfinal class Tool\n{\n{$body}}\n";
    }
}
