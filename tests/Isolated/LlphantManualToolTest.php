<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tests\Isolated;

use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\Tests\Support\RecordingClient;
use SpacedTracker\Ai\Tool\LibraryTools;
use SpacedTracker\Ai\Tool\SpaceTrackQueryAction;
use SpacedTracker\Ai\Tool\SpaceTrackTool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class LlphantManualToolTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testManualFunctionInfoCarriesTheSameRequiredParameters(): void
    {
        require_once dirname(__DIR__) . '/Support/Double/LLPhant/Chat/FunctionInfo/FunctionInfo.php';
        require_once dirname(__DIR__) . '/Support/Double/LLPhant/Chat/FunctionInfo/Parameter.php';
        self::assertFalse(class_exists(\LLPhant\Chat\FunctionInfo\FunctionBuilder::class));

        $client = new RecordingClient();
        $client->records = [['NORAD_CAT_ID' => '25544']];
        $info = LibraryTools::llphant(new SpaceTrackTool($client));

        self::assertInstanceOf(\LLPhant\Chat\FunctionInfo\FunctionInfo::class, $info);
        self::assertSame(SpaceTrackQueryAction::NAME, $info->name);
        self::assertSame(SpaceTrackQueryAction::DESCRIPTION, $info->description);
        self::assertInstanceOf(SpaceTrackQueryAction::class, $info->instance);
        self::assertCount(8, $info->parameters);
        self::assertCount(3, $info->requiredParameters);
        self::assertSame($info->parameters[0], $info->requiredParameters[0]);
        self::assertSame($info->parameters[1], $info->requiredParameters[1]);
        self::assertSame($info->parameters[2], $info->requiredParameters[2]);
        self::assertSame(
            ['request_class', 'where', 'rationale', 'controller', 'order_by', 'direction', 'limit', 'format'],
            array_map(static fn (\LLPhant\Chat\FunctionInfo\Parameter $parameter): string => $parameter->name, $info->parameters),
        );
        self::assertSame('integer', $info->parameters[6]->type);
        self::assertSame(['asc', 'desc'], $info->parameters[5]->enum);

        $json = $info->instance->space_track_query('satcat', '{"NORAD_CAT_ID":"25544"}', 'catalog row', limit: 10);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('satcat', $decoded['class']);
        self::assertSame(10, $client->queries[0]->limitCount());
        self::assertSame('25544', $client->queries[0]->criteria()[0]->value);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testABuilderThatReturnsNoObjectIsRejected(): void
    {
        require_once dirname(__DIR__) . '/Support/Isolated/LLPhant/FunctionBuilder.php';

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('did not return a FunctionInfo');
        LibraryTools::llphant(new SpaceTrackTool(new RecordingClient()));
    }
}
