<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tests\Adapter;

use SpacedTracker\Ai\Adapter\OptionalSdk;
use SpacedTracker\Ai\Exception\IntegrationException;
use PHPUnit\Framework\TestCase;

final class OptionalSdkTest extends TestCase
{
    public function testCallsInstalledClassesAndReportsMissingOnes(): void
    {
        self::assertSame('LLPhant\\Chat\\FunctionInfo\\FunctionInfo', OptionalSdk::className('LLPhant', 'Chat', 'FunctionInfo', 'FunctionInfo'));

        $created = OptionalSdk::new(\stdClass::class);
        self::assertInstanceOf(\stdClass::class, $created);

        $pong = OptionalSdk::call(new class {
            public function ping(string $value): string
            {
                return 'pong:' . $value;
            }
        }, 'ping', 'ok');
        self::assertSame('pong:ok', $pong);

        $date = OptionalSdk::callStatic(\DateTimeImmutable::class, 'createFromFormat', '!Y-m-d', '2026-01-01');
        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('2026-01-01', $date->format('Y-m-d'));

        try {
            OptionalSdk::new('SpacedTracker\\Ai\\MissingPackage\\Client');
            self::fail('A missing class must be reported as not installed.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('is not installed', $exception->getMessage());
        }

        try {
            OptionalSdk::call(new \stdClass(), 'missing');
            self::fail('A missing method must be reported as not callable.');
        } catch (IntegrationException $exception) {
            self::assertSame('missing is not callable.', $exception->getMessage());
        }

        try {
            OptionalSdk::callStatic(\stdClass::class, 'missing');
            self::fail('A missing static method must be reported as not callable.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('stdClass::missing is not callable', $exception->getMessage());
        }
    }
}
