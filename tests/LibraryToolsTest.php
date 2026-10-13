<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tests;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SpacedTracker\Ai\Exception\IntegrationException;
use SpacedTracker\Ai\Tests\Support\RecordingClient;
use SpacedTracker\Ai\Tool\LibraryTools;
use SpacedTracker\Ai\Tool\SpaceTrackQueryAction;
use SpacedTracker\Ai\Tool\SpaceTrackTool;
use PHPUnit\Framework\TestCase;

final class LibraryToolsTest extends TestCase
{
    public function testQueryActionRunsASelectiveCatalogQuery(): void
    {
        $client = new RecordingClient();
        $client->records = [['NORAD_CAT_ID' => '25544', 'OBJECT_NAME' => 'ISS (ZARYA)']];
        $action = new SpaceTrackQueryAction(new SpaceTrackTool($client));

        $json = $action->space_track_query(
            'satcat',
            '{"NORAD_CAT_ID":"25544"}',
            'catalog row',
            limit: 10,
        );
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('satcat', $decoded['class']);
        self::assertSame('satcat', $client->queries[0]->className());
        self::assertSame('basicspacedata', $client->queries[0]->controller());
        self::assertSame(10, $client->queries[0]->limitCount());
        self::assertSame('NORAD_CAT_ID', $client->queries[0]->criteria()[0]->field);
        self::assertSame('25544', $client->queries[0]->criteria()[0]->value);

        $fromArray = $action->fromArguments('gp', ['NORAD_CAT_ID' => '25544'], 'elements', limit: '10');
        $second = json_decode($fromArray, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($second);
        self::assertSame('gp', $second['class']);
        self::assertSame(10, $client->queries[1]->limitCount());

        $sorted = $action->fromArguments('satcat', '', 'sorted catalog', 'basicspacedata', 'EPOCH', 'desc', 2.9, 'tle');
        $sortedBody = json_decode($sorted, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sortedBody);
        self::assertSame('satcat', $client->queries[2]->className());
        self::assertSame('basicspacedata', $client->queries[2]->controller());
        self::assertSame([], $client->queries[2]->criteria());
        self::assertSame('EPOCH', $client->queries[2]->orders()[0]->field);
        self::assertSame('desc', $client->queries[2]->orders()[0]->direction->value);
        self::assertSame(2, $client->queries[2]->limitCount());
        self::assertSame('tle', $client->queries[2]->outputFormat()->value);

        $invalid = $action->space_track_query('decay', 'not-json', 'messages');
        $third = json_decode($invalid, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($third);
        self::assertSame('decay', $client->queries[3]->className());
        self::assertSame([], $client->queries[3]->criteria());
    }

    public function testSymfonyAttributeNamesTheQueryMethod(): void
    {
        $attributes = (new \ReflectionClass(SpaceTrackQueryAction::class))->getAttributes();

        self::assertSame('Symfony\\AI\\Agent\\Toolbox\\Attribute\\AsTool', $attributes[0]->getName());
        self::assertSame('space_track_query', $attributes[0]->getArguments()['name']);
        self::assertSame('space_track_query', $attributes[0]->getArguments()['method']);
        self::assertSame(SpaceTrackQueryAction::DESCRIPTION, $attributes[0]->getArguments()['description']);
        self::assertFalse(class_exists('Symfony\\AI\\Agent\\Toolbox\\Attribute\\AsTool', false));

        $action = LibraryTools::symfony(new SpaceTrackTool(new RecordingClient()));
        self::assertInstanceOf(SpaceTrackQueryAction::class, $action);
    }

    public function testBridgesUseTheRealLibraryShapes(): void
    {
        $tool = new SpaceTrackTool(new RecordingClient());
        $this->assertPackageMissing(static fn () => LibraryTools::llphant($tool), 'theodo-group/llphant');
        $this->assertPackageMissing(static fn () => LibraryTools::neuron($tool), 'neuron-core/neuron-ai');
        $this->assertPackageMissing(static fn () => LibraryTools::prism($tool), 'prism-php/prism');
        $this->assertPackageMissing(static fn () => LibraryTools::laravel($tool), 'laravel/ai');

        $this->loadOptionalDouble('LLPhant\\Chat\\FunctionInfo\\FunctionInfo', 'LLPhant/Chat/FunctionInfo/FunctionInfo.php');
        $this->loadOptionalDouble('LLPhant\\Chat\\FunctionInfo\\FunctionBuilder', 'LLPhant/Chat/FunctionInfo/FunctionBuilder.php');
        $info = LibraryTools::llphant($tool);
        self::assertInstanceOf(\LLPhant\Chat\FunctionInfo\FunctionInfo::class, $info);
        self::assertSame('space_track_query', $info->name);
        self::assertInstanceOf(SpaceTrackQueryAction::class, $info->instance);

        $this->loadOptionalDouble('NeuronAI\\Tools\\PropertyType', 'NeuronAI/Tools/PropertyType.php');
        $this->loadOptionalDouble('NeuronAI\\Tools\\ToolProperty', 'NeuronAI/Tools/ToolProperty.php');
        $this->loadOptionalDouble('NeuronAI\\Tools\\Tool', 'NeuronAI/Tools/Tool.php');
        $neuronClient = new RecordingClient();
        $neuronClient->records = [['NORAD_CAT_ID' => '25544']];
        $neuronTool = LibraryTools::neuron(new SpaceTrackTool($neuronClient));
        self::assertSame('space_track_query', $neuronTool->getName());
        $properties = $neuronTool->getProperties();
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
        self::assertTrue($properties[2]->isRequired());
        self::assertFalse($properties[5]->isRequired());
        self::assertSame(['asc', 'desc'], $properties[5]->getEnum());
        self::assertSame(\NeuronAI\Tools\PropertyType::INTEGER, $properties[6]->getType());
        self::assertFalse($properties[6]->isRequired());
        $neuronJson = $neuronTool->__invoke(
            request_class: 'satcat',
            where: ['NORAD_CAT_ID' => '25544'],
            rationale: 'catalog row',
            limit: 10,
        );
        $neuronDecoded = json_decode($neuronJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($neuronDecoded);
        self::assertSame('satcat', $neuronDecoded['class']);
        self::assertSame(10, $neuronClient->queries[0]->limitCount());
        self::assertSame('25544', $neuronClient->queries[0]->criteria()[0]->value);

        $this->loadOptionalDouble('Prism\\Prism\\Tool', 'Prism/Prism/Tool.php');
        $prismClient = new RecordingClient();
        $prismClient->records = [['NORAD_CAT_ID' => '25544']];
        $prismTool = LibraryTools::prism(new SpaceTrackTool($prismClient));
        self::assertSame('space_track_query', $prismTool->name());
        $limit = null;
        foreach ($prismTool->defined as $parameter) {
            if ($parameter['name'] === 'limit') {
                $limit = $parameter;
            }
        }
        self::assertIsArray($limit);
        self::assertSame('number', $limit['kind']);
        self::assertFalse($limit['required']);
        $prismJson = $prismTool->handle(
            request_class: 'satcat',
            where: '{"NORAD_CAT_ID":"25544"}',
            rationale: 'catalog row',
            limit: 10,
        );
        $prismDecoded = json_decode($prismJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($prismDecoded);
        self::assertSame('satcat', $prismDecoded['class']);
        self::assertSame(10, $prismClient->queries[0]->limitCount());

        $this->loadOptionalDouble('Illuminate\\JsonSchema\\Types\\Type', 'Illuminate/JsonSchema/Types/Type.php');
        $this->loadOptionalDouble('Illuminate\\JsonSchema\\Types\\StringType', 'Illuminate/JsonSchema/Types/StringType.php');
        $this->loadOptionalDouble('Illuminate\\JsonSchema\\Types\\IntegerType', 'Illuminate/JsonSchema/Types/IntegerType.php');
        $this->loadOptionalDouble('Illuminate\\Contracts\\JsonSchema\\JsonSchema', 'Illuminate/Contracts/JsonSchema/JsonSchema.php');
        $this->loadOptionalDouble('Laravel\\Ai\\Tools\\Request', 'Laravel/Ai/Tools/Request.php');
        $this->loadOptionalDouble('Laravel\\Ai\\Contracts\\Tool', 'Laravel/Ai/Contracts/Tool.php');
        $laravelClient = new RecordingClient();
        $laravelClient->records = [['NORAD_CAT_ID' => '25544']];
        $laravelTool = LibraryTools::laravel(new SpaceTrackTool($laravelClient));
        self::assertSame(SpaceTrackQueryAction::DESCRIPTION, $laravelTool->description());
        $schema = new class implements JsonSchema {
            public function object(\Closure|array $properties = []): mixed
            {
                unset($properties);

                return null;
            }

            public function array(): mixed
            {
                return null;
            }

            public function string(): \Illuminate\JsonSchema\Types\StringType
            {
                return new \Illuminate\JsonSchema\Types\StringType();
            }

            public function integer(): \Illuminate\JsonSchema\Types\IntegerType
            {
                return new \Illuminate\JsonSchema\Types\IntegerType();
            }

            public function number(): mixed
            {
                return null;
            }

            public function boolean(): mixed
            {
                return null;
            }

            /**
             * @param array<int, string> $types
             */
            public function union(array $types): mixed
            {
                unset($types);

                return null;
            }

            public function anyOf(\Closure|array $schemas): mixed
            {
                unset($schemas);

                return null;
            }
        };
        $fields = $laravelTool->schema($schema);
        self::assertTrue($fields['request_class']->isRequired);
        self::assertFalse($fields['request_class']->isNullable);
        self::assertTrue($fields['controller']->isNullable);
        self::assertTrue($fields['limit']->isNullable);
        self::assertFalse($fields['limit']->isRequired);
        $laravelJson = $laravelTool->handle(new \Laravel\Ai\Tools\Request([
            'request_class' => 'satcat',
            'where' => ['NORAD_CAT_ID' => '25544'],
            'rationale' => 'catalog row',
            'limit' => '10',
        ]));
        $laravelDecoded = json_decode($laravelJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($laravelDecoded);
        self::assertSame('satcat', $laravelDecoded['class']);
        self::assertSame(10, $laravelClient->queries[0]->limitCount());

        try {
            $laravelTool->handle(new \Laravel\Ai\Tools\Request(['request_class' => 'satcat']));
            self::fail('A Laravel tool request without rationale must throw.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('rationale', $exception->getMessage());
        }
    }

    private function assertPackageMissing(callable $call, string $package): void
    {
        try {
            $call();
            self::fail($package . ' must be reported missing.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString($package, $exception->getMessage());
        }
    }

    private function loadOptionalDouble(string $class, string $path): void
    {
        if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
            return;
        }

        require_once __DIR__ . '/Support/Double/' . $path;
    }
}
