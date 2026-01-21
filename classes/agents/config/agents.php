<?php

/**
 * @file classes/agents/config/agents.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Default configuration for the 7-Node Agent Blueprint.
 *
 * This configuration can be overridden in config.inc.php under [agents] section.
 */

return [
    /**
     * Global agent settings
     */
    'enabled' => true,
    'max_iterations' => 10,
    'timeout' => 120,
    'log_level' => 'info', // debug, info, warning, error

    /**
     * LLM Node configuration
     */
    'llm' => [
        'provider' => 'openai', // openai, anthropic, ollama
        'model' => 'gpt-4o-mini',
        'temperature' => 0.7,
        'max_tokens' => 4096,
        'streaming' => false,
    ],

    /**
     * Tool Node configuration
     */
    'tools' => [
        'sandbox_mode' => true,
        'allowed_tools' => ['*'], // ['get_submission', 'search_users', ...]
        'rate_limit' => 100, // calls per minute
    ],

    /**
     * Control Node configuration
     */
    'control' => [
        'default_route' => 'fallback',
        'max_loop_iterations' => 100,
        'strict_mode' => false,
    ],

    /**
     * Memory Node configuration
     */
    'memory' => [
        'storage_backend' => 'database', // database, redis, file
        'max_conversation_turns' => 50,
        'enable_embeddings' => false,
        'embedding_model' => 'text-embedding-3-small',
        'similarity_threshold' => 0.7,
    ],

    /**
     * Guardrail Node configuration
     */
    'guardrails' => [
        'strict_mode' => true,
        'enable_pii_detection' => true,
        'enable_toxicity_check' => true,
        'max_output_length' => 50000,
        'required_fields' => [],
    ],

    /**
     * Fallback Node configuration
     */
    'fallback' => [
        'max_retries' => 3,
        'initial_delay_ms' => 1000,
        'max_delay_ms' => 30000,
        'backoff_multiplier' => 2.0,
        'circuit_breaker_threshold' => 5,
        'circuit_breaker_timeout' => 60,
        'enable_notifications' => true,
    ],

    /**
     * User Input Node configuration
     */
    'user_input' => [
        'default_timeout' => 86400, // 24 hours
        'auto_approve_low_risk' => false,
        'notification_method' => 'email', // email, notification, both
        'require_approval_for' => ['publish', 'delete', 'bulk_action', 'payment'],
    ],

    /**
     * Provider API keys (can also be set via environment variables)
     * OPENAI_API_KEY, ANTHROPIC_API_KEY
     */
    'api_keys' => [
        // 'openai' => 'sk-...',
        // 'anthropic' => 'sk-ant-...',
    ],

    /**
     * Ollama configuration (for local models)
     */
    'ollama' => [
        'base_url' => 'http://localhost:11434',
        'model' => 'llama3.2',
    ],
];
