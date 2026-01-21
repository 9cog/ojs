<?php

/**
 * @file classes/agents/nodes/ControlNode.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ControlNode
 *
 * @brief Control Node - Handles logic routing and deterministic branching.
 *
 * This node manages workflow control including:
 * - Conditional routing based on data
 * - Switch/case logic
 * - Loop control
 * - Parallel execution coordination
 * - State machine transitions
 */

namespace APP\agents\nodes;

class ControlNode extends BaseAgentNode
{
    public const NODE_TYPE = 'control';

    /** @var array Registered routes */
    protected array $routes = [];

    /** @var array State machine definition */
    protected array $stateMachine = [];

    /**
     * @copydoc AgentNodeInterface::getNodeType()
     */
    public function getNodeType(): string
    {
        return self::NODE_TYPE;
    }

    /**
     * @copydoc BaseAgentNode::getDefaultConfig()
     */
    protected function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'default_route' => 'fallback',
            'max_iterations' => 100,
            'strict_mode' => false,
        ]);
    }

    /**
     * @copydoc AgentNodeInterface::canHandle()
     */
    public function canHandle(array $input): bool
    {
        return isset($input['control_type']) || isset($input['condition']) || isset($input['route']);
    }

    /**
     * @copydoc AgentNodeInterface::process()
     */
    public function process(array $input, array $context = []): array
    {
        $controlType = $input['control_type'] ?? 'route';

        try {
            $result = match ($controlType) {
                'route' => $this->handleRoute($input, $context),
                'switch' => $this->handleSwitch($input, $context),
                'condition' => $this->handleCondition($input, $context),
                'loop' => $this->handleLoop($input, $context),
                'parallel' => $this->handleParallel($input, $context),
                'state' => $this->handleStateTransition($input, $context),
                default => throw new \InvalidArgumentException("Unknown control type: {$controlType}"),
            };

            $this->logExecution($input, $result);
            return $this->buildResponse(true, $result);
        } catch (\Exception $e) {
            $this->logExecution($input, [], $e->getMessage());
            return $this->buildResponse(false, null, $e->getMessage());
        }
    }

    /**
     * Register a route.
     */
    public function registerRoute(string $name, callable $condition, string $target): void
    {
        $this->routes[$name] = [
            'condition' => $condition,
            'target' => $target,
        ];
    }

    /**
     * Define state machine.
     */
    public function defineStateMachine(array $definition): void
    {
        $this->stateMachine = $definition;
    }

    /**
     * Handle routing based on conditions.
     */
    protected function handleRoute(array $input, array $context): array
    {
        $data = $input['data'] ?? [];

        foreach ($this->routes as $name => $route) {
            if (call_user_func($route['condition'], $data, $context)) {
                return [
                    'action' => 'route',
                    'matched_route' => $name,
                    'target' => $route['target'],
                    'data' => $data,
                ];
            }
        }

        return [
            'action' => 'route',
            'matched_route' => 'default',
            'target' => $this->getConfig('default_route'),
            'data' => $data,
        ];
    }

    /**
     * Handle switch/case logic.
     */
    protected function handleSwitch(array $input, array $context): array
    {
        $value = $input['value'] ?? null;
        $cases = $input['cases'] ?? [];
        $default = $input['default'] ?? null;

        foreach ($cases as $case => $result) {
            if ($this->matchCase($value, $case)) {
                return [
                    'action' => 'switch',
                    'matched_case' => $case,
                    'result' => $result,
                    'value' => $value,
                ];
            }
        }

        return [
            'action' => 'switch',
            'matched_case' => 'default',
            'result' => $default,
            'value' => $value,
        ];
    }

    /**
     * Match a case value (supports patterns).
     */
    protected function matchCase(mixed $value, mixed $case): bool
    {
        // Exact match
        if ($value === $case) {
            return true;
        }

        // Pattern match (regex)
        if (is_string($case) && str_starts_with($case, '/') && str_ends_with($case, '/')) {
            return preg_match($case, (string) $value) === 1;
        }

        // Range match (for numbers)
        if (is_string($case) && str_contains($case, '..')) {
            [$min, $max] = explode('..', $case);
            return $value >= (float) $min && $value <= (float) $max;
        }

        // Array membership
        if (is_array($case)) {
            return in_array($value, $case, true);
        }

        return false;
    }

    /**
     * Handle conditional branching.
     */
    protected function handleCondition(array $input, array $context): array
    {
        $condition = $input['condition'] ?? '';
        $data = array_merge($context, $input['data'] ?? []);

        $result = $this->evaluateCondition($condition, $data);

        return [
            'action' => 'condition',
            'condition' => $condition,
            'result' => $result,
            'branch' => $result ? ($input['then'] ?? 'continue') : ($input['else'] ?? 'stop'),
        ];
    }

    /**
     * Evaluate a condition expression safely.
     */
    protected function evaluateCondition(string $condition, array $data): bool
    {
        // Simple expression parser
        // Supports: ==, !=, <, >, <=, >=, &&, ||, !
        // Variables accessed via $data['key']

        // Replace variable references
        $expression = preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)/',
            fn($m) => var_export($data[$m[1]] ?? null, true),
            $condition
        );

        // Whitelist allowed tokens
        if (!preg_match('/^[\s\d\.\'\"\[\]a-zA-Z_=!<>&|()null,\-]+$/', $expression)) {
            throw new \InvalidArgumentException('Invalid condition expression');
        }

        return (bool) eval("return {$expression};");
    }

    /**
     * Handle loop iteration.
     */
    protected function handleLoop(array $input, array $context): array
    {
        $items = $input['items'] ?? [];
        $maxIterations = min(
            $input['max_iterations'] ?? PHP_INT_MAX,
            $this->getConfig('max_iterations')
        );

        $results = [];
        $iteration = 0;

        foreach ($items as $key => $item) {
            if ($iteration >= $maxIterations) {
                break;
            }

            $results[] = [
                'index' => $iteration,
                'key' => $key,
                'item' => $item,
                'action' => $input['action'] ?? 'process',
            ];

            $iteration++;
        }

        return [
            'action' => 'loop',
            'total_items' => count($items),
            'processed' => $iteration,
            'results' => $results,
            'complete' => $iteration >= count($items),
        ];
    }

    /**
     * Handle parallel execution coordination.
     */
    protected function handleParallel(array $input, array $context): array
    {
        $tasks = $input['tasks'] ?? [];

        return [
            'action' => 'parallel',
            'tasks' => array_map(fn($task, $idx) => [
                'id' => $task['id'] ?? "task_{$idx}",
                'type' => $task['type'] ?? 'unknown',
                'status' => 'pending',
            ], $tasks, array_keys($tasks)),
            'total_tasks' => count($tasks),
        ];
    }

    /**
     * Handle state machine transition.
     */
    protected function handleStateTransition(array $input, array $context): array
    {
        $currentState = $input['current_state'] ?? $context['state'] ?? 'initial';
        $event = $input['event'] ?? null;

        if (empty($this->stateMachine)) {
            throw new \RuntimeException('No state machine defined');
        }

        $transitions = $this->stateMachine['transitions'] ?? [];
        $newState = $currentState;

        foreach ($transitions as $transition) {
            if (
                $transition['from'] === $currentState &&
                $transition['event'] === $event
            ) {
                $newState = $transition['to'];
                break;
            }
        }

        return [
            'action' => 'state_transition',
            'previous_state' => $currentState,
            'event' => $event,
            'new_state' => $newState,
            'transitioned' => $newState !== $currentState,
        ];
    }
}
