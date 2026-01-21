<?php

/**
 * @file classes/agents/AgentOrchestrator.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AgentOrchestrator
 *
 * @brief Orchestrates the 7-Node Agent Blueprint for autonomous operation.
 *
 * The orchestrator coordinates all agent nodes to process complex tasks:
 * 1. LLM Node - AI reasoning and decision-making
 * 2. Tool Node - External integrations (APIs, databases)
 * 3. Control Node - Logic routing and branching
 * 4. Memory Node - Context/conversation persistence
 * 5. Guardrail Node - Output validation and quality checks
 * 6. Fallback Node - Error handling and retries
 * 7. User Input Node - Human-in-the-loop approvals
 */

namespace APP\agents;

use APP\agents\nodes\LLMNode;
use APP\agents\nodes\ToolNode;
use APP\agents\nodes\ControlNode;
use APP\agents\nodes\MemoryNode;
use APP\agents\nodes\GuardrailNode;
use APP\agents\nodes\FallbackNode;
use APP\agents\nodes\UserInputNode;
use APP\agents\contracts\AgentNodeInterface;
use PKP\config\Config;

class AgentOrchestrator
{
    /** @var array<string, AgentNodeInterface> Registered nodes */
    protected array $nodes = [];

    /** @var array Execution pipeline */
    protected array $pipeline = [];

    /** @var array Execution history */
    protected array $history = [];

    /** @var array Current execution context */
    protected array $context = [];

    /** @var array Configuration */
    protected array $config;

    /** @var string Session ID */
    protected string $sessionId;

    /**
     * Constructor - Initialize all 7 nodes.
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->sessionId = $config['session_id'] ?? uniqid('agent_session_', true);

        $this->initializeNodes();
    }

    /**
     * Get default configuration.
     */
    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'max_iterations' => 10,
            'timeout' => 120,
            'log_level' => 'info',
            'require_guardrails' => true,
            'auto_memory' => true,
            'llm' => [
                'provider' => Config::getVar('agents', 'llm_provider', 'openai'),
                'model' => Config::getVar('agents', 'llm_model', 'gpt-4o-mini'),
            ],
        ];
    }

    /**
     * Initialize all 7 agent nodes.
     */
    protected function initializeNodes(): void
    {
        // 1. LLM Node - The brain
        $this->registerNode(new LLMNode([
            'provider' => $this->config['llm']['provider'] ?? 'openai',
            'model' => $this->config['llm']['model'] ?? 'gpt-4o-mini',
            'system_prompt' => $this->getSystemPrompt(),
        ]));

        // 2. Tool Node - External integrations
        $this->registerNode(new ToolNode([
            'sandbox_mode' => true,
        ]));

        // 3. Control Node - Logic routing
        $this->registerNode(new ControlNode([
            'default_route' => 'fallback',
        ]));

        // 4. Memory Node - Context persistence
        $this->registerNode(new MemoryNode([
            'session_id' => $this->sessionId,
            'enable_embeddings' => Config::getVar('agents', 'enable_embeddings', false),
        ]));

        // 5. Guardrail Node - Validation
        $this->registerNode(new GuardrailNode([
            'strict_mode' => true,
            'enable_pii_detection' => true,
        ]));

        // 6. Fallback Node - Error handling
        $this->registerNode(new FallbackNode([
            'max_retries' => 3,
            'enable_notifications' => true,
        ]));

        // 7. User Input Node - Human-in-the-loop
        $this->registerNode(new UserInputNode([
            'auto_approve_low_risk' => false,
        ]));
    }

    /**
     * Get the system prompt for the LLM.
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
You are an intelligent assistant for Open Journal Systems (OJS), a scholarly publishing platform.

Your capabilities include:
- Answering questions about journal management, submissions, and publishing workflows
- Helping with manuscript handling, peer review coordination, and editorial decisions
- Assisting with user management, role assignments, and permissions
- Providing guidance on DOI registration, ORCID integration, and metadata management
- Supporting statistical analysis and reporting

Always be helpful, accurate, and mindful of academic publishing best practices.
When you need to perform actions, use the available tools. When uncertain, ask for clarification.
PROMPT;
    }

    /**
     * Register a node.
     */
    public function registerNode(AgentNodeInterface $node): void
    {
        $this->nodes[$node->getNodeType()] = $node;
    }

    /**
     * Get a node by type.
     */
    public function getNode(string $type): ?AgentNodeInterface
    {
        return $this->nodes[$type] ?? null;
    }

    /**
     * Execute a task through the agent pipeline.
     */
    public function execute(array $input, array $context = []): array
    {
        $this->context = array_merge([
            'session_id' => $this->sessionId,
            'started_at' => date('c'),
            'iteration' => 0,
        ], $context);

        $executionId = uniqid('exec_', true);

        try {
            // Store input in memory
            if ($this->config['auto_memory']) {
                $this->executeNode('memory', [
                    'memory_action' => 'store',
                    'key' => 'input_' . $executionId,
                    'value' => $input,
                    'metadata' => ['type' => 'user_input'],
                ]);
            }

            // Main execution loop
            $result = $this->runPipeline($input);

            // Validate output through guardrails
            if ($this->config['require_guardrails']) {
                $validated = $this->executeNode('guardrail', [
                    'data' => $result['data'] ?? $result,
                    'checks' => ['length', 'pii'],
                ]);

                if (!$validated['success'] || !($validated['data']['passed'] ?? true)) {
                    // Attempt recovery through fallback
                    $recovered = $this->executeNode('fallback', [
                        'error' => $validated['data']['results']['errors'] ?? [],
                        'original_input' => $input,
                    ]);

                    if ($recovered['data']['should_retry'] ?? false) {
                        return $this->execute($input, array_merge($context, ['retry' => true]));
                    }
                }
            }

            // Store result in memory
            if ($this->config['auto_memory']) {
                $this->executeNode('memory', [
                    'memory_action' => 'store',
                    'key' => 'output_' . $executionId,
                    'value' => $result,
                    'metadata' => ['type' => 'agent_output'],
                ]);
            }

            return [
                'success' => true,
                'execution_id' => $executionId,
                'session_id' => $this->sessionId,
                'result' => $result,
                'metadata' => [
                    'iterations' => $this->context['iteration'],
                    'duration_ms' => $this->calculateDuration(),
                ],
            ];
        } catch (\Exception $e) {
            // Handle through fallback node
            $fallbackResult = $this->executeNode('fallback', [
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                ],
                'operation' => 'execute',
            ]);

            return [
                'success' => false,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
                'fallback' => $fallbackResult['data'] ?? null,
            ];
        }
    }

    /**
     * Run the main processing pipeline.
     */
    protected function runPipeline(array $input): array
    {
        $maxIterations = $this->config['max_iterations'];
        $currentInput = $input;

        while ($this->context['iteration'] < $maxIterations) {
            $this->context['iteration']++;

            // Retrieve relevant memory context
            $memoryContext = $this->getMemoryContext($currentInput);

            // Execute LLM for reasoning
            $llmResult = $this->executeNode('llm', array_merge($currentInput, [
                'context' => $memoryContext,
            ]));

            if (!$llmResult['success']) {
                return $llmResult;
            }

            $response = $llmResult['data'];

            // Check if LLM wants to use a tool
            if ($this->needsToolCall($response)) {
                $toolResult = $this->handleToolCall($response);

                // Feed tool result back to LLM
                $currentInput = [
                    'messages' => [
                        ['role' => 'assistant', 'content' => $response['content'] ?? ''],
                        ['role' => 'tool', 'content' => json_encode($toolResult)],
                    ],
                ];
                continue;
            }

            // Check if human input is needed
            if ($this->needsUserInput($response)) {
                return $this->handleUserInput($response, $currentInput);
            }

            // Check control flow
            $controlResult = $this->checkControlFlow($response);
            if ($controlResult['action'] === 'continue') {
                $currentInput = $controlResult['next_input'] ?? $currentInput;
                continue;
            }

            // Done - return final response
            return $response;
        }

        return [
            'content' => 'Maximum iterations reached',
            'truncated' => true,
        ];
    }

    /**
     * Execute a specific node.
     */
    protected function executeNode(string $nodeType, array $input): array
    {
        $node = $this->getNode($nodeType);

        if (!$node) {
            throw new \RuntimeException("Node not found: {$nodeType}");
        }

        $result = $node->process($input, $this->context);

        // Log execution
        $this->history[] = [
            'node' => $nodeType,
            'input_hash' => md5(json_encode($input)),
            'success' => $result['success'] ?? false,
            'timestamp' => date('c'),
        ];

        return $result;
    }

    /**
     * Get relevant memory context.
     */
    protected function getMemoryContext(array $input): array
    {
        $query = $input['prompt'] ?? $input['messages'][0]['content'] ?? '';

        if (empty($query)) {
            return [];
        }

        $memoryResult = $this->executeNode('memory', [
            'memory_action' => 'search',
            'query' => $query,
            'limit' => 5,
        ]);

        return $memoryResult['data']['results'] ?? [];
    }

    /**
     * Check if response indicates tool call needed.
     */
    protected function needsToolCall(array $response): bool
    {
        $content = $response['content'] ?? '';

        // Check for tool call indicators
        return str_contains($content, '[TOOL:') ||
               str_contains($content, '```tool') ||
               isset($response['tool_calls']);
    }

    /**
     * Handle tool call from LLM.
     */
    protected function handleToolCall(array $response): array
    {
        // Extract tool call from response
        $content = $response['content'] ?? '';

        // Parse tool call (simple format: [TOOL:name] {params})
        if (preg_match('/\[TOOL:(\w+)\]\s*(\{[^}]+\})?/', $content, $matches)) {
            $toolName = $matches[1];
            $params = json_decode($matches[2] ?? '{}', true) ?? [];

            return $this->executeNode('tool', [
                'tool' => $toolName,
                'parameters' => $params,
            ]);
        }

        // Handle structured tool_calls
        if (isset($response['tool_calls'])) {
            $results = [];
            foreach ($response['tool_calls'] as $call) {
                $results[$call['id'] ?? uniqid()] = $this->executeNode('tool', [
                    'tool' => $call['function']['name'] ?? $call['name'],
                    'parameters' => json_decode($call['function']['arguments'] ?? '{}', true),
                ]);
            }
            return $results;
        }

        return ['error' => 'Could not parse tool call'];
    }

    /**
     * Check if user input is needed.
     */
    protected function needsUserInput(array $response): bool
    {
        $content = $response['content'] ?? '';

        return str_contains($content, '[APPROVAL_NEEDED]') ||
               str_contains($content, '[CLARIFICATION_NEEDED]') ||
               str_contains($content, '[CONFIRM]');
    }

    /**
     * Handle user input request.
     */
    protected function handleUserInput(array $response, array $originalInput): array
    {
        $content = $response['content'] ?? '';

        if (str_contains($content, '[APPROVAL_NEEDED]')) {
            return $this->executeNode('user_input', [
                'user_input_type' => 'approval',
                'action' => 'agent_action',
                'details' => ['original_input' => $originalInput],
            ]);
        }

        if (str_contains($content, '[CLARIFICATION_NEEDED]')) {
            return $this->executeNode('user_input', [
                'user_input_type' => 'clarification',
                'question' => $content,
            ]);
        }

        return $response;
    }

    /**
     * Check control flow for continuation.
     */
    protected function checkControlFlow(array $response): array
    {
        $content = $response['content'] ?? '';

        // Check for continuation markers
        if (str_contains($content, '[CONTINUE]')) {
            return ['action' => 'continue'];
        }

        return ['action' => 'stop'];
    }

    /**
     * Calculate execution duration.
     */
    protected function calculateDuration(): float
    {
        $started = strtotime($this->context['started_at']);
        return (microtime(true) - $started) * 1000;
    }

    /**
     * Chat interface - simplified execution.
     */
    public function chat(string $message, ?int $userId = null): array
    {
        return $this->execute([
            'prompt' => $message,
        ], [
            'user_id' => $userId,
        ]);
    }

    /**
     * Get execution history.
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Get session ID.
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Reset the orchestrator.
     */
    public function reset(): void
    {
        foreach ($this->nodes as $node) {
            $node->reset();
        }

        $this->history = [];
        $this->context = [];
    }

    /**
     * Get status of all nodes.
     */
    public function getStatus(): array
    {
        return [
            'session_id' => $this->sessionId,
            'nodes' => array_map(fn($n) => [
                'type' => $n->getNodeType(),
                'state' => $n->getState(),
            ], $this->nodes),
            'history_count' => count($this->history),
            'config' => $this->config,
        ];
    }
}
