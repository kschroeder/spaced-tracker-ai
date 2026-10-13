<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tests\Isolated;

use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\Tests\Support\RecordingClient;
use SpacedTracker\Ai\Tool\LibraryTools;
use SpacedTracker\Ai\Tool\SpaceTrackTool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class NeuronConcreteToolTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConcreteToolRegistersPropertiesAndRunsTheCallable(): void
    {
        $this->loadSharedDoubles();
        $this->load('NeuronV3/Tool.php');
        $client = new RecordingClient();
        $client->records = [['NORAD_CAT_ID' => '25544']];
        $tool = LibraryTools::neuron(new SpaceTrackTool($client));

        self::assertInstanceOf(\NeuronAI\Tools\Tool::class, $tool);
        self::assertFalse((new \ReflectionClass($tool))->isAbstract());
        self::assertSame('space_track_query', $tool->getName());
        $properties = $tool->getProperties();
        self::assertCount(8, $properties);
        $names = [];
        foreach ($properties as $property) {
            self::assertInstanceOf(\NeuronAI\Tools\ToolProperty::class, $property);
            $names[] = $property->getName();
        }
        self::assertSame(
            ['request_class', 'where', 'rationale', 'controller', 'order_by', 'direction', 'limit', 'format'],
            $names,
        );
        self::assertTrue($properties[0]->isRequired());
        self::assertSame(['asc', 'desc'], $properties[5]->getEnum());
        self::assertSame(\NeuronAI\Tools\PropertyType::INTEGER, $properties[6]->getType());
        self::assertFalse($properties[6]->isRequired());

        $json = $tool->execute(
            'satcat',
            '{"NORAD_CAT_ID":"25544"}',
            'catalog row',
            null,
            null,
            null,
            10,
        );
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('satcat', $decoded['class']);
        self::assertSame(10, $client->queries[0]->limitCount());
        self::assertSame('25544', $client->queries[0]->criteria()[0]->value);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVoidReturnsStillKeepTheToolAndItsCallable(): void
    {
        define('SPACED_TRACKER_NEURON_V3_VOID', true);
        $this->loadSharedDoubles();
        $this->load('NeuronV3/Tool.php');
        $client = new RecordingClient();
        $client->records = [['NORAD_CAT_ID' => '25544']];
        $tool = LibraryTools::neuron(new SpaceTrackTool($client));
        $json = $tool->execute('gp', '{"NORAD_CAT_ID":"25544"}', 'elements');
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('gp', $decoded['class']);
        self::assertSame('25544', $client->queries[0]->criteria()[0]->value);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAConcreteToolWithoutSetCallableIsRejected(): void
    {
        $this->load('NeuronV3/ToolWithoutCallable.php');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('setCallable()');
        LibraryTools::neuron(new SpaceTrackTool(new RecordingClient()));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAConcreteToolWithoutAddPropertyIsRejected(): void
    {
        $this->loadSharedDoubles();
        $this->load('NeuronV3/ToolWithoutAddProperty.php');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('addProperty()');
        LibraryTools::neuron(new SpaceTrackTool(new RecordingClient()));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPropertiesRequireThePropertyTypeEnum(): void
    {
        $this->loadDouble('NeuronAI/Tools/ToolProperty.php');
        $this->load('NeuronV3/Tool.php');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('neuron-core/neuron-ai is not installed.');
        LibraryTools::neuron(new SpaceTrackTool(new RecordingClient()));
    }

    private function loadSharedDoubles(): void
    {
        $this->loadDouble('NeuronAI/Tools/PropertyType.php');
        $this->loadDouble('NeuronAI/Tools/ToolProperty.php');
    }

    private function load(string $path): void
    {
        require_once dirname(__DIR__) . '/Support/Isolated/' . $path;
    }

    private function loadDouble(string $path): void
    {
        require_once dirname(__DIR__) . '/Support/Double/' . $path;
    }
}
