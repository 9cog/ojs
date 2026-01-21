<?php

/**
 * @file classes/agents/contracts/AgentContextInterface.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AgentContextInterface
 *
 * @brief Interface for agent execution context management.
 */

namespace APP\agents\contracts;

interface AgentContextInterface
{
    /**
     * Get a value from the context.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a value in the context.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if a key exists in the context.
     */
    public function has(string $key): bool;

    /**
     * Get all context data.
     */
    public function all(): array;

    /**
     * Merge additional data into the context.
     */
    public function merge(array $data): void;

    /**
     * Get the conversation/session ID.
     */
    public function getSessionId(): string;

    /**
     * Get the user ID if authenticated.
     */
    public function getUserId(): ?int;
}
