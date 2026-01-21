<?php

/**
 * @file classes/agents/contracts/AgentNodeInterface.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AgentNodeInterface
 *
 * @brief Interface for all agent nodes in the 7-Node Agent Blueprint architecture.
 *
 * The 7-Node Blueprint provides a modular, autonomous agent system:
 * - LLM Node: AI reasoning and decision-making
 * - Tool Node: External integrations (APIs, databases)
 * - Control Node: Logic routing and branching
 * - Memory Node: Context/conversation persistence
 * - Guardrail Node: Output validation and quality checks
 * - Fallback Node: Error handling and retries
 * - User Input Node: Human-in-the-loop approvals
 */

namespace APP\agents\contracts;

interface AgentNodeInterface
{
    /**
     * Get the unique identifier for this node type.
     */
    public function getNodeType(): string;

    /**
     * Process input and return output.
     *
     * @param array $input The input data to process
     * @param array $context Additional context from the orchestrator
     * @return array The processed output
     */
    public function process(array $input, array $context = []): array;

    /**
     * Check if this node can handle the given input.
     *
     * @param array $input The input to check
     * @return bool True if this node can process the input
     */
    public function canHandle(array $input): bool;

    /**
     * Get the node's current state.
     *
     * @return array The node's state data
     */
    public function getState(): array;

    /**
     * Reset the node to its initial state.
     */
    public function reset(): void;
}
