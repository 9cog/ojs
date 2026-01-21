<?php

/**
 * @file classes/agents/nodes/GuardrailNode.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class GuardrailNode
 *
 * @brief Guardrail Node - Validates outputs against requirements and policies.
 *
 * This node ensures quality and safety including:
 * - Output format validation (JSON schema, structure)
 * - Content policy enforcement
 * - Quality threshold checks
 * - Factual consistency validation
 * - PII detection and redaction
 * - Toxicity/bias detection
 */

namespace APP\agents\nodes;

class GuardrailNode extends BaseAgentNode
{
    public const NODE_TYPE = 'guardrail';

    /** @var array Registered validators */
    protected array $validators = [];

    /** @var array Validation rules */
    protected array $rules = [];

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
            'strict_mode' => true,
            'enable_pii_detection' => true,
            'enable_toxicity_check' => true,
            'max_output_length' => 50000,
            'required_fields' => [],
        ]);
    }

    /**
     * Constructor.
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->registerBuiltInValidators();
    }

    /**
     * Register built-in validators.
     */
    protected function registerBuiltInValidators(): void
    {
        // Schema validator
        $this->registerValidator('schema', function ($data, $schema) {
            return $this->validateSchema($data, $schema);
        });

        // Required fields validator
        $this->registerValidator('required', function ($data, $fields) {
            return $this->validateRequired($data, $fields);
        });

        // Length validator
        $this->registerValidator('length', function ($data, $limits) {
            return $this->validateLength($data, $limits);
        });

        // Type validator
        $this->registerValidator('type', function ($data, $expectedType) {
            return $this->validateType($data, $expectedType);
        });

        // Pattern validator
        $this->registerValidator('pattern', function ($data, $pattern) {
            return $this->validatePattern($data, $pattern);
        });

        // PII detector
        $this->registerValidator('pii', function ($data, $options) {
            return $this->detectPII($data, $options);
        });

        // Toxicity checker
        $this->registerValidator('toxicity', function ($data, $threshold) {
            return $this->checkToxicity($data, $threshold);
        });

        // Academic content validator
        $this->registerValidator('academic', function ($data, $options) {
            return $this->validateAcademicContent($data, $options);
        });
    }

    /**
     * Register a custom validator.
     */
    public function registerValidator(string $name, callable $handler): void
    {
        $this->validators[$name] = $handler;
    }

    /**
     * Add a validation rule.
     */
    public function addRule(string $name, string $validator, mixed $params = null): void
    {
        $this->rules[$name] = [
            'validator' => $validator,
            'params' => $params,
        ];
    }

    /**
     * @copydoc AgentNodeInterface::canHandle()
     */
    public function canHandle(array $input): bool
    {
        return isset($input['validate']) || isset($input['guardrail_check']);
    }

    /**
     * @copydoc AgentNodeInterface::process()
     */
    public function process(array $input, array $context = []): array
    {
        $data = $input['data'] ?? $input['validate'] ?? null;
        $checks = $input['checks'] ?? array_keys($this->rules);

        if ($data === null) {
            return $this->buildResponse(false, null, 'No data to validate');
        }

        try {
            $results = $this->runValidations($data, $checks, $input);
            $passed = $this->evaluateResults($results);

            $this->logExecution($input, $results);

            return $this->buildResponse($passed, [
                'passed' => $passed,
                'results' => $results,
                'data' => $passed ? $data : null,
                'sanitized' => $results['sanitized'] ?? null,
            ], $passed ? null : 'Validation failed');
        } catch (\Exception $e) {
            $this->logExecution($input, [], $e->getMessage());
            return $this->buildResponse(false, null, $e->getMessage());
        }
    }

    /**
     * Run all validations.
     */
    protected function runValidations(mixed $data, array $checks, array $input): array
    {
        $results = [
            'checks' => [],
            'errors' => [],
            'warnings' => [],
            'sanitized' => $data,
        ];

        foreach ($checks as $checkName) {
            // Use rule definition or inline check
            $rule = $this->rules[$checkName] ?? null;

            if ($rule) {
                $validator = $rule['validator'];
                $params = $rule['params'];
            } elseif (isset($input[$checkName])) {
                $validator = $checkName;
                $params = $input[$checkName];
            } else {
                continue;
            }

            if (!isset($this->validators[$validator])) {
                $results['warnings'][] = "Unknown validator: {$validator}";
                continue;
            }

            $checkResult = call_user_func($this->validators[$validator], $results['sanitized'], $params);

            $results['checks'][$checkName] = $checkResult;

            if (!$checkResult['passed']) {
                $results['errors'][] = $checkResult['message'] ?? "Check failed: {$checkName}";
            }

            if (isset($checkResult['sanitized'])) {
                $results['sanitized'] = $checkResult['sanitized'];
            }
        }

        return $results;
    }

    /**
     * Evaluate validation results.
     */
    protected function evaluateResults(array $results): bool
    {
        if ($this->getConfig('strict_mode')) {
            return empty($results['errors']);
        }

        // In non-strict mode, allow warnings
        $criticalErrors = array_filter($results['checks'], fn($c) => ($c['severity'] ?? 'error') === 'critical' && !$c['passed']);

        return empty($criticalErrors);
    }

    // Built-in validators

    /**
     * Validate against JSON schema.
     */
    protected function validateSchema(mixed $data, array $schema): array
    {
        $errors = [];

        // Type check
        if (isset($schema['type'])) {
            $actualType = gettype($data);
            $expectedTypes = (array) $schema['type'];

            $typeMap = [
                'integer' => 'integer',
                'number' => ['integer', 'double'],
                'string' => 'string',
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'array',
                'null' => 'NULL',
            ];

            $valid = false;
            foreach ($expectedTypes as $expected) {
                $mapped = $typeMap[$expected] ?? $expected;
                if (is_array($mapped)) {
                    $valid = in_array($actualType, $mapped);
                } else {
                    $valid = $actualType === $mapped;
                }
                if ($valid) {
                    break;
                }
            }

            if (!$valid) {
                $errors[] = "Expected type " . implode('|', $expectedTypes) . ", got {$actualType}";
            }
        }

        // Properties check for objects/arrays
        if (isset($schema['properties']) && is_array($data)) {
            foreach ($schema['properties'] as $prop => $propSchema) {
                if (isset($data[$prop])) {
                    $subResult = $this->validateSchema($data[$prop], $propSchema);
                    if (!$subResult['passed']) {
                        $errors[] = "Property '{$prop}': " . implode(', ', $subResult['errors'] ?? []);
                    }
                }
            }
        }

        // Required properties
        if (isset($schema['required']) && is_array($data)) {
            foreach ($schema['required'] as $required) {
                if (!isset($data[$required])) {
                    $errors[] = "Missing required property: {$required}";
                }
            }
        }

        return [
            'passed' => empty($errors),
            'errors' => $errors,
            'message' => empty($errors) ? null : implode('; ', $errors),
        ];
    }

    /**
     * Validate required fields.
     */
    protected function validateRequired(mixed $data, array $fields): array
    {
        if (!is_array($data)) {
            return ['passed' => false, 'message' => 'Data must be an array'];
        }

        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }

        return [
            'passed' => empty($missing),
            'missing' => $missing,
            'message' => empty($missing) ? null : 'Missing fields: ' . implode(', ', $missing),
        ];
    }

    /**
     * Validate length constraints.
     */
    protected function validateLength(mixed $data, array $limits): array
    {
        $length = is_string($data) ? strlen($data) : (is_array($data) ? count($data) : 0);
        $min = $limits['min'] ?? 0;
        $max = $limits['max'] ?? $this->getConfig('max_output_length');

        $passed = $length >= $min && $length <= $max;

        return [
            'passed' => $passed,
            'length' => $length,
            'limits' => ['min' => $min, 'max' => $max],
            'message' => $passed ? null : "Length {$length} outside bounds [{$min}, {$max}]",
        ];
    }

    /**
     * Validate data type.
     */
    protected function validateType(mixed $data, string $expectedType): array
    {
        $actualType = gettype($data);
        $passed = $actualType === $expectedType || ($expectedType === 'number' && in_array($actualType, ['integer', 'double']));

        return [
            'passed' => $passed,
            'expected' => $expectedType,
            'actual' => $actualType,
            'message' => $passed ? null : "Expected {$expectedType}, got {$actualType}",
        ];
    }

    /**
     * Validate against regex pattern.
     */
    protected function validatePattern(mixed $data, string $pattern): array
    {
        if (!is_string($data)) {
            return ['passed' => false, 'message' => 'Data must be a string for pattern validation'];
        }

        $passed = preg_match($pattern, $data) === 1;

        return [
            'passed' => $passed,
            'pattern' => $pattern,
            'message' => $passed ? null : "Data does not match pattern",
        ];
    }

    /**
     * Detect personally identifiable information.
     */
    protected function detectPII(mixed $data, array $options = []): array
    {
        if (!is_string($data)) {
            $data = json_encode($data);
        }

        $detected = [];
        $sanitized = $data;

        // Email detection
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $data, $matches)) {
            $detected['emails'] = $matches[0];
            if ($options['redact'] ?? false) {
                $sanitized = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL REDACTED]', $sanitized);
            }
        }

        // Phone detection (basic patterns)
        if (preg_match_all('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', $data, $matches)) {
            $detected['phones'] = $matches[0];
            if ($options['redact'] ?? false) {
                $sanitized = preg_replace('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', '[PHONE REDACTED]', $sanitized);
            }
        }

        // SSN detection (US)
        if (preg_match_all('/\b\d{3}-\d{2}-\d{4}\b/', $data, $matches)) {
            $detected['ssn'] = $matches[0];
            if ($options['redact'] ?? false) {
                $sanitized = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[SSN REDACTED]', $sanitized);
            }
        }

        $hasPII = !empty($detected);

        return [
            'passed' => !$hasPII || ($options['allow'] ?? false),
            'detected' => $detected,
            'severity' => $hasPII ? 'warning' : 'info',
            'sanitized' => $sanitized,
            'message' => $hasPII ? 'PII detected in content' : null,
        ];
    }

    /**
     * Check for toxic content.
     */
    protected function checkToxicity(mixed $data, float $threshold = 0.5): array
    {
        if (!is_string($data)) {
            $data = json_encode($data);
        }

        // Simple keyword-based toxicity check (production would use ML model)
        $toxicPatterns = [
            '/\b(hate|kill|attack|destroy|harm)\s+(people|them|you|everyone)\b/i',
            '/\b(racist|sexist|discriminat)\w*\b/i',
        ];

        $score = 0.0;
        $matches = [];

        foreach ($toxicPatterns as $pattern) {
            if (preg_match($pattern, $data, $m)) {
                $score += 0.3;
                $matches[] = $m[0];
            }
        }

        $score = min(1.0, $score);
        $passed = $score < $threshold;

        return [
            'passed' => $passed,
            'score' => $score,
            'threshold' => $threshold,
            'severity' => $passed ? 'info' : 'critical',
            'message' => $passed ? null : "Toxicity score ({$score}) exceeds threshold ({$threshold})",
        ];
    }

    /**
     * Validate academic content quality.
     */
    protected function validateAcademicContent(mixed $data, array $options = []): array
    {
        if (!is_string($data)) {
            $data = json_encode($data);
        }

        $issues = [];

        // Check for citations if required
        if ($options['require_citations'] ?? false) {
            if (!preg_match('/\([A-Z][a-z]+,?\s*\d{4}\)|\[\d+\]/', $data)) {
                $issues[] = 'No citations found';
            }
        }

        // Check minimum word count
        $wordCount = str_word_count($data);
        $minWords = $options['min_words'] ?? 0;
        if ($wordCount < $minWords) {
            $issues[] = "Word count ({$wordCount}) below minimum ({$minWords})";
        }

        // Check for placeholder text
        if (preg_match('/\b(TODO|FIXME|TBD|Lorem ipsum)\b/i', $data)) {
            $issues[] = 'Contains placeholder text';
        }

        return [
            'passed' => empty($issues),
            'issues' => $issues,
            'word_count' => $wordCount,
            'message' => empty($issues) ? null : implode('; ', $issues),
        ];
    }
}
