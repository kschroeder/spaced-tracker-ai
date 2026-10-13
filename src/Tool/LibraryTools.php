<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tool;

use SpacedTracker\Ai\Adapter\OptionalSdk;
use SpacedTracker\Ai\Exception\IntegrationException;

/**
 * Builds the tool object each PHP AI library registers.
 *
 * The SDKs stay optional. A missing package throws IntegrationException
 * instead of a fatal error while this file is loading.
 */
final class LibraryTools
{
    public static function llphant(SpaceTrackTool $tool): object
    {
        $action = new SpaceTrackQueryAction($tool);
        $builder = OptionalSdk::className('LLPhant', 'Chat', 'FunctionInfo', 'FunctionBuilder');
        if (class_exists($builder)) {
            $info = OptionalSdk::callStatic($builder, 'buildFunctionInfo', $action, SpaceTrackQueryAction::NAME);
            if (!is_object($info)) {
                throw new IntegrationException('theodo-group/llphant FunctionBuilder did not return a FunctionInfo.');
            }

            return $info;
        }

        $infoClass = OptionalSdk::className('LLPhant', 'Chat', 'FunctionInfo', 'FunctionInfo');
        $parameterClass = OptionalSdk::className('LLPhant', 'Chat', 'FunctionInfo', 'Parameter');
        if (!class_exists($infoClass) || !class_exists($parameterClass)) {
            throw new IntegrationException('theodo-group/llphant is not installed.');
        }

        $parameters = [];
        $required = [];
        foreach (self::fields() as $field) {
            $parameter = OptionalSdk::new($parameterClass, [
                $field['name'],
                $field['type'],
                $field['description'],
                $field['enum'],
            ]);
            $parameters[] = $parameter;
            if ($field['required']) {
                $required[] = $parameter;
            }
        }

        return OptionalSdk::new($infoClass, [
            SpaceTrackQueryAction::NAME,
            $action,
            SpaceTrackQueryAction::DESCRIPTION,
            $parameters,
            $required,
        ]);
    }

    public static function neuron(SpaceTrackTool $tool): object
    {
        $toolClass = OptionalSdk::className('NeuronAI', 'Tools', 'Tool');
        if (!class_exists($toolClass)) {
            throw new IntegrationException('neuron-core/neuron-ai is not installed.');
        }

        $action = new SpaceTrackQueryAction($tool);
        /** @var class-string $toolClass */
        $reflection = new \ReflectionClass($toolClass);
        // Neuron 3 is a concrete Tool and runs setCallable(). Neuron 4 Tool is abstract and runs __invoke().
        if ($reflection->isAbstract()) {
            return self::neuronAbstractTool($action);
        }
        if (!$reflection->hasMethod('setCallable')) {
            throw new IntegrationException('neuron-core/neuron-ai Tool must expose setCallable().');
        }

        return self::neuronConcreteTool($toolClass, $action);
    }

    public static function prism(SpaceTrackTool $tool): object
    {
        $toolClass = OptionalSdk::className('Prism', 'Prism', 'Tool');
        if (!class_exists($toolClass)) {
            throw new IntegrationException('prism-php/prism is not installed.');
        }

        $action = new SpaceTrackQueryAction($tool);
        $prismTool = OptionalSdk::new($toolClass);
        $prismTool = self::prismCall($prismTool, 'as', SpaceTrackQueryAction::NAME);
        $prismTool = self::prismCall($prismTool, 'for', SpaceTrackQueryAction::DESCRIPTION);
        foreach (self::fields() as $field) {
            $prismTool = self::prismParameter($prismTool, $field);
        }

        return self::prismUsing($prismTool, $action);
    }

    public static function symfony(SpaceTrackTool $tool): SpaceTrackQueryAction
    {
        return new SpaceTrackQueryAction($tool);
    }

    public static function laravel(SpaceTrackTool $tool): object
    {
        $contract = OptionalSdk::className('Laravel', 'Ai', 'Contracts', 'Tool');
        $requestClass = OptionalSdk::className('Laravel', 'Ai', 'Tools', 'Request');
        $schemaClass = OptionalSdk::className('Illuminate', 'Contracts', 'JsonSchema', 'JsonSchema');
        if (!interface_exists($contract) || !class_exists($requestClass) || !interface_exists($schemaClass)) {
            throw new IntegrationException('laravel/ai is not installed.');
        }

        $action = new SpaceTrackQueryAction($tool);

        return new class ($action) implements \Laravel\Ai\Contracts\Tool {
            public function __construct(private readonly SpaceTrackQueryAction $action)
            {
            }

            public function description(): string
            {
                return SpaceTrackQueryAction::DESCRIPTION;
            }

            public function handle(\Laravel\Ai\Tools\Request $request): string
            {
                $requestClass = $request->offsetExists('request_class') ? $request->offsetGet('request_class') : null;
                $rationale = $request->offsetExists('rationale') ? $request->offsetGet('rationale') : null;
                if (!is_string($requestClass) || $requestClass === '' || !is_string($rationale) || $rationale === '') {
                    throw new IntegrationException('Laravel AI tool request requires request_class and rationale.');
                }

                return $this->action->fromArguments(
                    $requestClass,
                    $request->offsetExists('where') ? $request->offsetGet('where') : '{}',
                    $rationale,
                    $request->offsetExists('controller') ? $request->offsetGet('controller') : null,
                    $request->offsetExists('order_by') ? $request->offsetGet('order_by') : null,
                    $request->offsetExists('direction') ? $request->offsetGet('direction') : null,
                    $request->offsetExists('limit') ? $request->offsetGet('limit') : null,
                    $request->offsetExists('format') ? $request->offsetGet('format') : null,
                );
            }

            /**
             * @return array<string, mixed>
             */
            public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
            {
                return [
                    'request_class' => $schema->string()->description('Request class such as satcat, gp, decay, or tip')->required(),
                    'where' => $schema->string()->description('JSON object of Space-Track predicates')->required(),
                    'rationale' => $schema->string()->description('Why this query answers the user')->required(),
                    'controller' => $schema->string()->description('Space-Track controller when the class name is not unique')->nullable(),
                    'order_by' => $schema->string()->description('Field name to sort by')->nullable(),
                    'direction' => $schema->string()->description('Sort direction, asc or desc')->nullable(),
                    'limit' => $schema->integer()->description('Maximum rows, capped by the tool')->nullable(),
                    'format' => $schema->string()->description('Response format such as json, csv, or tle')->nullable(),
                ];
            }
        };
    }

    /**
     * @return list<\NeuronAI\Tools\ToolProperty>
     */
    public static function neuronProperties(): array
    {
        $properties = [];
        foreach (self::fields() as $field) {
            $properties[] = self::neuronProperty($field);
        }

        return $properties;
    }

    private static function neuronAbstractTool(SpaceTrackQueryAction $action): object
    {
        return new class ($action) extends \NeuronAI\Tools\Tool {
            public function __construct(private readonly SpaceTrackQueryAction $action)
            {
                $this->name = SpaceTrackQueryAction::NAME;
                $this->description = SpaceTrackQueryAction::DESCRIPTION;
            }

            /**
             * @return list<\NeuronAI\Tools\ToolProperty>
             */
            protected function properties(): array
            {
                return LibraryTools::neuronProperties();
            }

            public function __invoke(
                string $request_class,
                mixed $where,
                string $rationale,
                mixed $controller = null,
                mixed $order_by = null,
                mixed $direction = null,
                mixed $limit = null,
                mixed $format = null,
            ): string {
                return $this->action->fromArguments(
                    $request_class,
                    $where,
                    $rationale,
                    $controller,
                    $order_by,
                    $direction,
                    $limit,
                    $format,
                );
            }
        };
    }

    private static function neuronConcreteTool(string $toolClass, SpaceTrackQueryAction $action): object
    {
        $neuronTool = OptionalSdk::new($toolClass, [SpaceTrackQueryAction::NAME, SpaceTrackQueryAction::DESCRIPTION]);
        foreach (self::neuronProperties() as $property) {
            if (!method_exists($neuronTool, 'addProperty')) {
                throw new IntegrationException('neuron-core/neuron-ai Tool must expose addProperty().');
            }
            $updated = $neuronTool->addProperty($property);
            if (is_object($updated)) {
                $neuronTool = $updated;
            }
        }
        if (!method_exists($neuronTool, 'setCallable')) {
            throw new IntegrationException('neuron-core/neuron-ai Tool must expose setCallable().');
        }

        $updated = $neuronTool->setCallable($action->space_track_query(...));

        return is_object($updated) ? $updated : $neuronTool;
    }

    /**
     * @param array{name: string, description: string, type: string, required: bool, enum: list<string>} $field
     */
    private static function neuronProperty(array $field): \NeuronAI\Tools\ToolProperty
    {
        $propertyClass = OptionalSdk::className('NeuronAI', 'Tools', 'ToolProperty');
        $typeClass = OptionalSdk::className('NeuronAI', 'Tools', 'PropertyType');
        $case = $field['type'] === 'integer' ? 'INTEGER' : 'STRING';
        if (!class_exists($propertyClass) || !enum_exists($typeClass)) {
            throw new IntegrationException('neuron-core/neuron-ai is not installed.');
        }

        $property = OptionalSdk::new($propertyClass, [
            $field['name'],
            constant($typeClass . '::' . $case),
            $field['description'],
            $field['required'],
            $field['enum'],
        ]);
        if (!$property instanceof \NeuronAI\Tools\ToolProperty) {
            throw new IntegrationException('neuron-core/neuron-ai is not installed.');
        }

        return $property;
    }

    /**
     * @param array{name: string, description: string, type: string, required: bool, enum: list<string>} $field
     */
    private static function prismParameter(object $tool, array $field): object
    {
        if ($field['enum'] !== []) {
            if (!method_exists($tool, 'withEnumParameter')) {
                throw new IntegrationException('prism-php/prism Tool must expose withEnumParameter().');
            }
            $next = $tool->withEnumParameter($field['name'], $field['description'], $field['enum'], $field['required']);
        } elseif ($field['type'] === 'integer') {
            if (!method_exists($tool, 'withNumberParameter')) {
                throw new IntegrationException('prism-php/prism Tool must expose withNumberParameter().');
            }
            $next = $tool->withNumberParameter($field['name'], $field['description'], $field['required']);
        } else {
            if (!method_exists($tool, 'withStringParameter')) {
                throw new IntegrationException('prism-php/prism Tool must expose withStringParameter().');
            }
            $next = $tool->withStringParameter($field['name'], $field['description'], $field['required']);
        }
        if (!is_object($next)) {
            throw new IntegrationException('prism-php/prism Tool parameter methods must return the tool.');
        }

        return $next;
    }

    private static function prismUsing(object $tool, SpaceTrackQueryAction $action): object
    {
        if (!method_exists($tool, 'using')) {
            throw new IntegrationException('prism-php/prism Tool must expose using().');
        }

        $next = $tool->using(static function (
            string $request_class,
            mixed $where,
            string $rationale,
            mixed $controller = null,
            mixed $order_by = null,
            mixed $direction = null,
            mixed $limit = null,
            mixed $format = null,
        ) use ($action): string {
            return $action->fromArguments(
                $request_class,
                $where,
                $rationale,
                $controller,
                $order_by,
                $direction,
                $limit,
                $format,
            );
        });
        if (!is_object($next)) {
            throw new IntegrationException('prism-php/prism Tool must expose using().');
        }

        return $next;
    }

    private static function prismCall(object $tool, string $method, string $argument): object
    {
        if ($method === 'as') {
            if (!method_exists($tool, 'as')) {
                throw new IntegrationException('prism-php/prism Tool must expose as().');
            }
            $next = $tool->as($argument);
        } else {
            if (!method_exists($tool, 'for')) {
                throw new IntegrationException('prism-php/prism Tool must expose for().');
            }
            $next = $tool->for($argument);
        }
        if (!is_object($next)) {
            throw new IntegrationException('prism-php/prism Tool must expose ' . $method . '().');
        }

        return $next;
    }

    /**
     * @return list<array{name: string, description: string, type: string, required: bool, enum: list<string>}>
     */
    private static function fields(): array
    {
        return [
            [
                'name' => 'request_class',
                'description' => 'Request class such as satcat, gp, decay, or tip',
                'type' => 'string',
                'required' => true,
                'enum' => [],
            ],
            [
                'name' => 'where',
                'description' => 'JSON object of Space-Track predicates',
                'type' => 'string',
                'required' => true,
                'enum' => [],
            ],
            [
                'name' => 'rationale',
                'description' => 'Why this query answers the user',
                'type' => 'string',
                'required' => true,
                'enum' => [],
            ],
            [
                'name' => 'controller',
                'description' => 'Space-Track controller when the class name is not unique',
                'type' => 'string',
                'required' => false,
                'enum' => [],
            ],
            [
                'name' => 'order_by',
                'description' => 'Field name to sort by',
                'type' => 'string',
                'required' => false,
                'enum' => [],
            ],
            [
                'name' => 'direction',
                'description' => 'Sort direction, asc or desc',
                'type' => 'string',
                'required' => false,
                'enum' => ['asc', 'desc'],
            ],
            [
                'name' => 'limit',
                'description' => 'Maximum rows, capped by the tool',
                'type' => 'integer',
                'required' => false,
                'enum' => [],
            ],
            [
                'name' => 'format',
                'description' => 'Response format such as json, csv, or tle',
                'type' => 'string',
                'required' => false,
                'enum' => [],
            ],
        ];
    }
}
