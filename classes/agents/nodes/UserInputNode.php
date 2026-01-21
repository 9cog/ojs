<?php

/**
 * @file classes/agents/nodes/UserInputNode.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class UserInputNode
 *
 * @brief User Input Node - Enables human-in-the-loop for critical decisions.
 *
 * This node manages human interaction including:
 * - Approval workflows for sensitive operations
 * - Feedback collection for AI improvement
 * - Clarification requests
 * - Confirmation dialogs
 * - User preference input
 */

namespace APP\agents\nodes;

use PKP\notification\Notification;

class UserInputNode extends BaseAgentNode
{
    public const NODE_TYPE = 'user_input';

    /** @var array Pending approval requests */
    protected array $pendingApprovals = [];

    /** @var array Collected responses */
    protected array $responses = [];

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
            'default_timeout' => 86400, // 24 hours
            'auto_approve_low_risk' => false,
            'notification_method' => 'email', // email, notification, both
            'require_approval_for' => ['publish', 'delete', 'bulk_action', 'payment'],
        ]);
    }

    /**
     * @copydoc AgentNodeInterface::canHandle()
     */
    public function canHandle(array $input): bool
    {
        return isset($input['user_input_type']) ||
               isset($input['approval_request']) ||
               isset($input['clarification']) ||
               isset($input['confirmation']);
    }

    /**
     * @copydoc AgentNodeInterface::process()
     */
    public function process(array $input, array $context = []): array
    {
        $inputType = $input['user_input_type'] ?? 'auto';

        try {
            $result = match ($inputType) {
                'approval' => $this->requestApproval($input, $context),
                'clarification' => $this->requestClarification($input, $context),
                'confirmation' => $this->requestConfirmation($input, $context),
                'feedback' => $this->collectFeedback($input, $context),
                'choice' => $this->presentChoices($input, $context),
                'form' => $this->presentForm($input, $context),
                'check_response' => $this->checkResponse($input['request_id']),
                'submit_response' => $this->submitResponse($input, $context),
                'auto' => $this->autoProcess($input, $context),
                default => throw new \InvalidArgumentException("Unknown input type: {$inputType}"),
            };

            $this->logExecution($input, $result);
            return $this->buildResponse(true, $result);
        } catch (\Exception $e) {
            $this->logExecution($input, [], $e->getMessage());
            return $this->buildResponse(false, null, $e->getMessage());
        }
    }

    /**
     * Auto-determine input type based on content.
     */
    protected function autoProcess(array $input, array $context): array
    {
        if (isset($input['approval_request'])) {
            return $this->requestApproval($input, $context);
        }

        if (isset($input['clarification'])) {
            return $this->requestClarification($input, $context);
        }

        if (isset($input['confirmation'])) {
            return $this->requestConfirmation($input, $context);
        }

        if (isset($input['choices'])) {
            return $this->presentChoices($input, $context);
        }

        return [
            'action' => 'none',
            'message' => 'No user input action determined',
        ];
    }

    /**
     * Request approval for an action.
     */
    protected function requestApproval(array $input, array $context): array
    {
        $requestId = uniqid('approval_', true);
        $action = $input['action'] ?? $input['approval_request'] ?? 'unknown';
        $userId = $context['user_id'] ?? $input['user_id'] ?? null;
        $details = $input['details'] ?? [];
        $riskLevel = $input['risk_level'] ?? $this->assessRisk($action, $details);
        $timeout = $input['timeout'] ?? $this->getConfig('default_timeout');

        // Auto-approve low risk if configured
        if ($this->getConfig('auto_approve_low_risk') && $riskLevel === 'low') {
            return [
                'request_id' => $requestId,
                'action' => 'approval',
                'status' => 'auto_approved',
                'risk_level' => $riskLevel,
                'approved' => true,
                'auto' => true,
            ];
        }

        // Store pending approval
        $this->pendingApprovals[$requestId] = [
            'id' => $requestId,
            'action' => $action,
            'user_id' => $userId,
            'details' => $details,
            'risk_level' => $riskLevel,
            'status' => 'pending',
            'created_at' => date('c'),
            'expires_at' => date('c', time() + $timeout),
            'context' => $context,
        ];

        // Send notification
        $this->notifyUser($userId, 'approval', [
            'request_id' => $requestId,
            'action' => $action,
            'details' => $details,
            'risk_level' => $riskLevel,
        ]);

        return [
            'request_id' => $requestId,
            'action' => 'approval',
            'status' => 'pending',
            'risk_level' => $riskLevel,
            'requires_action' => true,
            'expires_at' => date('c', time() + $timeout),
            'approval_url' => $this->generateApprovalUrl($requestId),
        ];
    }

    /**
     * Assess risk level of an action.
     */
    protected function assessRisk(string $action, array $details): string
    {
        $highRiskActions = $this->getConfig('require_approval_for');

        foreach ($highRiskActions as $riskyAction) {
            if (str_contains(strtolower($action), $riskyAction)) {
                return 'high';
            }
        }

        // Check for bulk operations
        if (isset($details['count']) && $details['count'] > 10) {
            return 'medium';
        }

        // Check for external effects
        if (isset($details['external']) && $details['external']) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Request clarification from user.
     */
    protected function requestClarification(array $input, array $context): array
    {
        $requestId = uniqid('clarify_', true);
        $question = $input['question'] ?? $input['clarification'] ?? '';
        $suggestions = $input['suggestions'] ?? [];
        $userId = $context['user_id'] ?? $input['user_id'] ?? null;

        $this->pendingApprovals[$requestId] = [
            'id' => $requestId,
            'type' => 'clarification',
            'question' => $question,
            'suggestions' => $suggestions,
            'user_id' => $userId,
            'status' => 'pending',
            'created_at' => date('c'),
            'context' => $context,
        ];

        $this->notifyUser($userId, 'clarification', [
            'request_id' => $requestId,
            'question' => $question,
            'suggestions' => $suggestions,
        ]);

        return [
            'request_id' => $requestId,
            'action' => 'clarification',
            'status' => 'pending',
            'question' => $question,
            'suggestions' => $suggestions,
            'requires_action' => true,
        ];
    }

    /**
     * Request confirmation for an action.
     */
    protected function requestConfirmation(array $input, array $context): array
    {
        $requestId = uniqid('confirm_', true);
        $message = $input['message'] ?? $input['confirmation'] ?? '';
        $action = $input['action'] ?? 'proceed';
        $userId = $context['user_id'] ?? $input['user_id'] ?? null;

        $this->pendingApprovals[$requestId] = [
            'id' => $requestId,
            'type' => 'confirmation',
            'message' => $message,
            'action' => $action,
            'user_id' => $userId,
            'status' => 'pending',
            'created_at' => date('c'),
            'context' => $context,
        ];

        return [
            'request_id' => $requestId,
            'action' => 'confirmation',
            'status' => 'pending',
            'message' => $message,
            'confirm_action' => $action,
            'requires_action' => true,
            'options' => [
                ['value' => 'confirm', 'label' => 'Confirm'],
                ['value' => 'cancel', 'label' => 'Cancel'],
            ],
        ];
    }

    /**
     * Collect feedback from user.
     */
    protected function collectFeedback(array $input, array $context): array
    {
        $requestId = uniqid('feedback_', true);
        $subject = $input['subject'] ?? 'general';
        $prompt = $input['prompt'] ?? 'Please provide your feedback';
        $ratingScale = $input['rating_scale'] ?? 5;
        $userId = $context['user_id'] ?? $input['user_id'] ?? null;

        $this->pendingApprovals[$requestId] = [
            'id' => $requestId,
            'type' => 'feedback',
            'subject' => $subject,
            'prompt' => $prompt,
            'rating_scale' => $ratingScale,
            'user_id' => $userId,
            'status' => 'pending',
            'created_at' => date('c'),
            'context' => $context,
        ];

        return [
            'request_id' => $requestId,
            'action' => 'feedback',
            'status' => 'pending',
            'subject' => $subject,
            'prompt' => $prompt,
            'rating_scale' => $ratingScale,
            'requires_action' => true,
        ];
    }

    /**
     * Present choices to user.
     */
    protected function presentChoices(array $input, array $context): array
    {
        $requestId = uniqid('choice_', true);
        $question = $input['question'] ?? 'Please select an option';
        $choices = $input['choices'] ?? [];
        $allowMultiple = $input['allow_multiple'] ?? false;
        $userId = $context['user_id'] ?? $input['user_id'] ?? null;

        $this->pendingApprovals[$requestId] = [
            'id' => $requestId,
            'type' => 'choice',
            'question' => $question,
            'choices' => $choices,
            'allow_multiple' => $allowMultiple,
            'user_id' => $userId,
            'status' => 'pending',
            'created_at' => date('c'),
            'context' => $context,
        ];

        return [
            'request_id' => $requestId,
            'action' => 'choice',
            'status' => 'pending',
            'question' => $question,
            'choices' => $choices,
            'allow_multiple' => $allowMultiple,
            'requires_action' => true,
        ];
    }

    /**
     * Present a form to user.
     */
    protected function presentForm(array $input, array $context): array
    {
        $requestId = uniqid('form_', true);
        $title = $input['title'] ?? 'Input Required';
        $fields = $input['fields'] ?? [];
        $userId = $context['user_id'] ?? $input['user_id'] ?? null;

        $this->pendingApprovals[$requestId] = [
            'id' => $requestId,
            'type' => 'form',
            'title' => $title,
            'fields' => $fields,
            'user_id' => $userId,
            'status' => 'pending',
            'created_at' => date('c'),
            'context' => $context,
        ];

        return [
            'request_id' => $requestId,
            'action' => 'form',
            'status' => 'pending',
            'title' => $title,
            'fields' => $fields,
            'requires_action' => true,
        ];
    }

    /**
     * Check status of a pending request.
     */
    protected function checkResponse(string $requestId): array
    {
        if (!isset($this->pendingApprovals[$requestId])) {
            // Check stored responses
            if (isset($this->responses[$requestId])) {
                return [
                    'request_id' => $requestId,
                    'status' => 'completed',
                    'response' => $this->responses[$requestId],
                ];
            }

            return [
                'request_id' => $requestId,
                'status' => 'not_found',
            ];
        }

        $request = $this->pendingApprovals[$requestId];

        // Check expiration
        if (isset($request['expires_at']) && strtotime($request['expires_at']) < time()) {
            return [
                'request_id' => $requestId,
                'status' => 'expired',
                'expired_at' => $request['expires_at'],
            ];
        }

        return [
            'request_id' => $requestId,
            'status' => $request['status'],
            'type' => $request['type'] ?? 'unknown',
            'created_at' => $request['created_at'],
        ];
    }

    /**
     * Submit a response to a pending request.
     */
    protected function submitResponse(array $input, array $context): array
    {
        $requestId = $input['request_id'] ?? null;

        if (!$requestId || !isset($this->pendingApprovals[$requestId])) {
            return [
                'success' => false,
                'message' => 'Invalid or expired request',
            ];
        }

        $request = $this->pendingApprovals[$requestId];
        $response = $input['response'] ?? null;

        // Validate response based on type
        $valid = match ($request['type'] ?? 'unknown') {
            'approval' => in_array($response, ['approved', 'rejected']),
            'confirmation' => in_array($response, ['confirm', 'cancel']),
            'choice' => $this->validateChoice($response, $request),
            'clarification', 'feedback', 'form' => $response !== null,
            default => true,
        };

        if (!$valid) {
            return [
                'success' => false,
                'request_id' => $requestId,
                'message' => 'Invalid response for request type',
            ];
        }

        // Store response
        $this->responses[$requestId] = [
            'request_id' => $requestId,
            'response' => $response,
            'user_id' => $context['user_id'] ?? $input['user_id'] ?? null,
            'submitted_at' => date('c'),
            'original_request' => $request,
        ];

        // Remove from pending
        unset($this->pendingApprovals[$requestId]);

        return [
            'success' => true,
            'request_id' => $requestId,
            'response' => $response,
            'status' => 'completed',
            'approved' => $response === 'approved' || $response === 'confirm',
        ];
    }

    /**
     * Validate a choice response.
     */
    protected function validateChoice(mixed $response, array $request): bool
    {
        $choices = $request['choices'] ?? [];
        $allowMultiple = $request['allow_multiple'] ?? false;

        $validValues = array_column($choices, 'value');

        if ($allowMultiple && is_array($response)) {
            return empty(array_diff($response, $validValues));
        }

        return in_array($response, $validValues);
    }

    /**
     * Notify user about a request.
     */
    protected function notifyUser(?int $userId, string $type, array $data): void
    {
        if (!$userId) {
            return;
        }

        $method = $this->getConfig('notification_method');

        // TODO: Integrate with OJS notification system
        // This would create a PKP\notification\Notification

        error_log(sprintf(
            '[AgentUserInput] Notification to user %d: %s - %s',
            $userId,
            $type,
            json_encode($data)
        ));
    }

    /**
     * Generate URL for approval action.
     */
    protected function generateApprovalUrl(string $requestId): string
    {
        // TODO: Generate actual URL using OJS router
        return "/agent/approve/{$requestId}";
    }

    /**
     * Get all pending requests.
     */
    public function getPendingRequests(): array
    {
        return $this->pendingApprovals;
    }

    /**
     * Get pending requests for a specific user.
     */
    public function getPendingRequestsForUser(int $userId): array
    {
        return array_filter(
            $this->pendingApprovals,
            fn($r) => ($r['user_id'] ?? null) === $userId
        );
    }
}
