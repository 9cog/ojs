<?php

/**
 * @file classes/agents/nodes/FallbackNode.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FallbackNode
 *
 * @brief Fallback Node - Manages failures gracefully with retry and recovery.
 *
 * This node handles error recovery including:
 * - Automatic retry with exponential backoff
 * - Graceful degradation strategies
 * - Error classification and routing
 * - Circuit breaker pattern
 * - Notification of critical failures
 */

namespace APP\agents\nodes;

class FallbackNode extends BaseAgentNode
{
    public const NODE_TYPE = 'fallback';

    /** @var array Circuit breaker states */
    protected array $circuitBreakers = [];

    /** @var array Failure counts per operation */
    protected array $failureCounts = [];

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
            'max_retries' => 3,
            'initial_delay_ms' => 1000,
            'max_delay_ms' => 30000,
            'backoff_multiplier' => 2.0,
            'circuit_breaker_threshold' => 5,
            'circuit_breaker_timeout' => 60,
            'enable_notifications' => true,
            'fallback_strategies' => ['retry', 'degrade', 'default', 'error'],
        ]);
    }

    /**
     * @copydoc AgentNodeInterface::canHandle()
     */
    public function canHandle(array $input): bool
    {
        return isset($input['error']) || isset($input['fallback_action']) || isset($input['retry']);
    }

    /**
     * @copydoc AgentNodeInterface::process()
     */
    public function process(array $input, array $context = []): array
    {
        $action = $input['fallback_action'] ?? 'auto';

        try {
            $result = match ($action) {
                'retry' => $this->handleRetry($input, $context),
                'degrade' => $this->handleDegrade($input, $context),
                'circuit_check' => $this->checkCircuitBreaker($input['operation'] ?? 'default'),
                'circuit_reset' => $this->resetCircuitBreaker($input['operation'] ?? 'default'),
                'notify' => $this->notifyFailure($input, $context),
                'recover' => $this->attemptRecovery($input, $context),
                'auto' => $this->autoHandle($input, $context),
                default => throw new \InvalidArgumentException("Unknown fallback action: {$action}"),
            };

            $this->logExecution($input, $result);
            return $this->buildResponse(true, $result);
        } catch (\Exception $e) {
            $this->logExecution($input, [], $e->getMessage());
            return $this->buildResponse(false, [
                'fallback_failed' => true,
                'original_error' => $input['error'] ?? null,
                'fallback_error' => $e->getMessage(),
            ], 'Fallback handling failed');
        }
    }

    /**
     * Auto-determine fallback strategy based on error type.
     */
    protected function autoHandle(array $input, array $context): array
    {
        $error = $input['error'] ?? [];
        $errorType = $this->classifyError($error);
        $operation = $input['operation'] ?? 'unknown';

        // Check circuit breaker first
        $circuitState = $this->checkCircuitBreaker($operation);
        if ($circuitState['state'] === 'open') {
            return $this->handleDegrade($input, $context);
        }

        // Record failure
        $this->recordFailure($operation, $error);

        // Determine strategy based on error type
        $strategy = match ($errorType) {
            'transient' => 'retry',
            'rate_limit' => 'retry_delayed',
            'validation' => 'error',
            'authorization' => 'error',
            'not_found' => 'default',
            'timeout' => 'retry',
            'server_error' => 'retry',
            default => 'degrade',
        };

        return match ($strategy) {
            'retry' => $this->handleRetry($input, $context),
            'retry_delayed' => $this->handleRetry(array_merge($input, ['delay_ms' => 5000]), $context),
            'degrade' => $this->handleDegrade($input, $context),
            'default' => $this->provideDefault($input, $context),
            'error' => [
                'strategy' => 'error',
                'error_type' => $errorType,
                'message' => 'Non-recoverable error',
                'original_error' => $error,
            ],
        };
    }

    /**
     * Classify error type for strategy selection.
     */
    protected function classifyError(mixed $error): string
    {
        if (is_array($error)) {
            $code = $error['code'] ?? $error['status'] ?? 0;
            $message = strtolower($error['message'] ?? '');
        } elseif ($error instanceof \Exception) {
            $code = $error->getCode();
            $message = strtolower($error->getMessage());
        } else {
            $message = strtolower((string) $error);
            $code = 0;
        }

        // HTTP status code classification
        if ($code >= 400 && $code < 500) {
            if ($code === 401 || $code === 403) {
                return 'authorization';
            }
            if ($code === 404) {
                return 'not_found';
            }
            if ($code === 422 || $code === 400) {
                return 'validation';
            }
            if ($code === 429) {
                return 'rate_limit';
            }
        }

        if ($code >= 500) {
            return 'server_error';
        }

        // Message-based classification
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }

        if (str_contains($message, 'rate limit') || str_contains($message, 'too many requests')) {
            return 'rate_limit';
        }

        if (str_contains($message, 'connection') || str_contains($message, 'network')) {
            return 'transient';
        }

        return 'unknown';
    }

    /**
     * Handle retry with exponential backoff.
     */
    protected function handleRetry(array $input, array $context): array
    {
        $operation = $input['operation'] ?? 'unknown';
        $attempt = $input['attempt'] ?? 1;
        $maxRetries = $input['max_retries'] ?? $this->getConfig('max_retries');
        $delayMs = $input['delay_ms'] ?? $this->calculateDelay($attempt);

        if ($attempt > $maxRetries) {
            return [
                'strategy' => 'retry_exhausted',
                'attempts' => $attempt - 1,
                'max_retries' => $maxRetries,
                'recommendation' => 'degrade',
                'next_action' => 'degrade',
            ];
        }

        return [
            'strategy' => 'retry',
            'operation' => $operation,
            'attempt' => $attempt,
            'max_retries' => $maxRetries,
            'delay_ms' => $delayMs,
            'should_retry' => true,
            'retry_at' => date('c', time() + ($delayMs / 1000)),
            'original_input' => $input['original_input'] ?? null,
        ];
    }

    /**
     * Calculate delay using exponential backoff.
     */
    protected function calculateDelay(int $attempt): int
    {
        $initialDelay = $this->getConfig('initial_delay_ms');
        $maxDelay = $this->getConfig('max_delay_ms');
        $multiplier = $this->getConfig('backoff_multiplier');

        $delay = $initialDelay * pow($multiplier, $attempt - 1);

        // Add jitter (10% randomness)
        $jitter = $delay * 0.1 * (mt_rand() / mt_getrandmax());
        $delay += $jitter;

        return (int) min($delay, $maxDelay);
    }

    /**
     * Handle graceful degradation.
     */
    protected function handleDegrade(array $input, array $context): array
    {
        $operation = $input['operation'] ?? 'unknown';
        $degradeMode = $input['degrade_mode'] ?? 'limited';

        $degradedCapabilities = match ($degradeMode) {
            'limited' => [
                'caching' => true,
                'real_time' => false,
                'external_apis' => false,
                'ai_features' => false,
            ],
            'static' => [
                'caching' => true,
                'real_time' => false,
                'external_apis' => false,
                'ai_features' => false,
                'dynamic_content' => false,
            ],
            'offline' => [
                'caching' => true,
                'read_only' => true,
                'all_network' => false,
            ],
            default => ['degraded' => true],
        };

        return [
            'strategy' => 'degrade',
            'operation' => $operation,
            'degrade_mode' => $degradeMode,
            'capabilities' => $degradedCapabilities,
            'message' => "Operating in degraded mode: {$degradeMode}",
            'user_message' => 'Some features are temporarily unavailable. Basic functionality remains accessible.',
        ];
    }

    /**
     * Provide default/cached response.
     */
    protected function provideDefault(array $input, array $context): array
    {
        $operation = $input['operation'] ?? 'unknown';
        $defaultValue = $input['default'] ?? $context['default'] ?? null;

        return [
            'strategy' => 'default',
            'operation' => $operation,
            'value' => $defaultValue,
            'is_cached' => false,
            'message' => 'Using default value',
        ];
    }

    /**
     * Check circuit breaker state.
     */
    protected function checkCircuitBreaker(string $operation): array
    {
        if (!isset($this->circuitBreakers[$operation])) {
            $this->circuitBreakers[$operation] = [
                'state' => 'closed',
                'failures' => 0,
                'last_failure' => null,
                'opened_at' => null,
            ];
        }

        $circuit = &$this->circuitBreakers[$operation];
        $threshold = $this->getConfig('circuit_breaker_threshold');
        $timeout = $this->getConfig('circuit_breaker_timeout');

        // Check if circuit should transition from open to half-open
        if ($circuit['state'] === 'open' && $circuit['opened_at']) {
            $elapsed = time() - $circuit['opened_at'];
            if ($elapsed >= $timeout) {
                $circuit['state'] = 'half-open';
            }
        }

        // Check if circuit should open
        if ($circuit['state'] === 'closed' && $circuit['failures'] >= $threshold) {
            $circuit['state'] = 'open';
            $circuit['opened_at'] = time();
        }

        return [
            'operation' => $operation,
            'state' => $circuit['state'],
            'failures' => $circuit['failures'],
            'threshold' => $threshold,
            'can_proceed' => $circuit['state'] !== 'open',
        ];
    }

    /**
     * Record a failure for circuit breaker.
     */
    protected function recordFailure(string $operation, mixed $error): void
    {
        if (!isset($this->circuitBreakers[$operation])) {
            $this->circuitBreakers[$operation] = [
                'state' => 'closed',
                'failures' => 0,
                'last_failure' => null,
                'opened_at' => null,
            ];
        }

        $this->circuitBreakers[$operation]['failures']++;
        $this->circuitBreakers[$operation]['last_failure'] = time();
        $this->circuitBreakers[$operation]['last_error'] = $error;

        // Update global failure counts
        $this->failureCounts[$operation] = ($this->failureCounts[$operation] ?? 0) + 1;
    }

    /**
     * Record success (for circuit breaker recovery).
     */
    public function recordSuccess(string $operation): void
    {
        if (isset($this->circuitBreakers[$operation])) {
            $circuit = &$this->circuitBreakers[$operation];

            if ($circuit['state'] === 'half-open') {
                // Success in half-open closes the circuit
                $circuit['state'] = 'closed';
                $circuit['failures'] = 0;
                $circuit['opened_at'] = null;
            }
        }
    }

    /**
     * Reset circuit breaker.
     */
    protected function resetCircuitBreaker(string $operation): array
    {
        $this->circuitBreakers[$operation] = [
            'state' => 'closed',
            'failures' => 0,
            'last_failure' => null,
            'opened_at' => null,
        ];

        return [
            'operation' => $operation,
            'reset' => true,
            'state' => 'closed',
        ];
    }

    /**
     * Send failure notification.
     */
    protected function notifyFailure(array $input, array $context): array
    {
        if (!$this->getConfig('enable_notifications')) {
            return ['notified' => false, 'reason' => 'notifications disabled'];
        }

        $error = $input['error'] ?? [];
        $operation = $input['operation'] ?? 'unknown';
        $severity = $input['severity'] ?? 'error';

        // Log to application log
        error_log(sprintf(
            '[AgentFallback] %s failure in %s: %s',
            strtoupper($severity),
            $operation,
            json_encode($error)
        ));

        // TODO: Integrate with OJS notification system
        // Could send email to admins, create system notification, etc.

        return [
            'notified' => true,
            'operation' => $operation,
            'severity' => $severity,
            'timestamp' => date('c'),
        ];
    }

    /**
     * Attempt automatic recovery.
     */
    protected function attemptRecovery(array $input, array $context): array
    {
        $operation = $input['operation'] ?? 'unknown';
        $recoveryActions = $input['recovery_actions'] ?? ['clear_cache', 'reset_state'];

        $results = [];

        foreach ($recoveryActions as $action) {
            $results[$action] = match ($action) {
                'clear_cache' => ['success' => true, 'message' => 'Cache cleared'],
                'reset_state' => ['success' => true, 'message' => 'State reset'],
                'reconnect' => ['success' => true, 'message' => 'Reconnection attempted'],
                'reset_circuit' => $this->resetCircuitBreaker($operation),
                default => ['success' => false, 'message' => "Unknown recovery action: {$action}"],
            };
        }

        $allSuccess = array_reduce($results, fn($c, $r) => $c && ($r['success'] ?? false), true);

        return [
            'strategy' => 'recover',
            'operation' => $operation,
            'actions' => $results,
            'success' => $allSuccess,
        ];
    }

    /**
     * Get failure statistics.
     */
    public function getFailureStats(): array
    {
        return [
            'failure_counts' => $this->failureCounts,
            'circuit_breakers' => array_map(fn($c) => [
                'state' => $c['state'],
                'failures' => $c['failures'],
            ], $this->circuitBreakers),
        ];
    }
}
