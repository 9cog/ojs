<?php

/**
 * @file classes/agents/nodes/BaseAgentNode.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BaseAgentNode
 *
 * @brief Abstract base class for all agent nodes.
 */

namespace APP\agents\nodes;

use APP\agents\contracts\AgentNodeInterface;
use PKP\core\PKPApplication;

abstract class BaseAgentNode implements AgentNodeInterface
{
    /** @var array Node state */
    protected array $state = [];

    /** @var array Node configuration */
    protected array $config = [];

    /** @var array Execution history */
    protected array $history = [];

    /**
     * Constructor.
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->reset();
    }

    /**
     * Get default configuration for this node type.
     */
    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 30,
            'max_retries' => 3,
            'log_level' => 'info',
        ];
    }

    /**
     * @copydoc AgentNodeInterface::getState()
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * @copydoc AgentNodeInterface::reset()
     */
    public function reset(): void
    {
        $this->state = [
            'initialized' => true,
            'last_execution' => null,
            'execution_count' => 0,
            'errors' => [],
        ];
        $this->history = [];
    }

    /**
     * Log an execution event.
     */
    protected function logExecution(array $input, array $output, ?string $error = null): void
    {
        $entry = [
            'timestamp' => date('c'),
            'input_hash' => md5(json_encode($input)),
            'output_hash' => md5(json_encode($output)),
            'success' => $error === null,
            'error' => $error,
        ];

        $this->history[] = $entry;
        $this->state['last_execution'] = $entry['timestamp'];
        $this->state['execution_count']++;

        if ($error !== null) {
            $this->state['errors'][] = $error;
        }
    }

    /**
     * Get configuration value.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Build a standardized response.
     */
    protected function buildResponse(
        bool $success,
        mixed $data = null,
        ?string $message = null,
        array $metadata = []
    ): array {
        return [
            'success' => $success,
            'node_type' => $this->getNodeType(),
            'data' => $data,
            'message' => $message,
            'metadata' => array_merge([
                'timestamp' => date('c'),
                'execution_id' => uniqid('exec_', true),
            ], $metadata),
        ];
    }
}
