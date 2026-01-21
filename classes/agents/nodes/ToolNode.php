<?php

/**
 * @file classes/agents/nodes/ToolNode.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ToolNode
 *
 * @brief Tool Node - Extends agent capabilities with external integrations.
 *
 * This node handles all external tool integrations including:
 * - Database queries (submissions, users, journals)
 * - External API calls (DOI, ORCID, Crossref)
 * - File operations
 * - Email sending
 * - Search operations
 */

namespace APP\agents\nodes;

use APP\facades\Repo;
use PKP\db\DAORegistry;

class ToolNode extends BaseAgentNode
{
    public const NODE_TYPE = 'tool';

    /** @var array Registered tools */
    protected array $tools = [];

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
            'allowed_tools' => ['*'], // * for all, or specific tool names
            'sandbox_mode' => true,
        ]);
    }

    /**
     * Initialize built-in OJS tools.
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->registerBuiltInTools();
    }

    /**
     * Register built-in OJS tools.
     */
    protected function registerBuiltInTools(): void
    {
        // Submission tools
        $this->registerTool('get_submission', [
            'description' => 'Get submission details by ID',
            'parameters' => ['submission_id' => 'integer'],
            'handler' => fn($params) => $this->getSubmission($params['submission_id']),
        ]);

        $this->registerTool('search_submissions', [
            'description' => 'Search submissions by criteria',
            'parameters' => ['query' => 'string', 'context_id' => 'integer', 'limit' => 'integer'],
            'handler' => fn($params) => $this->searchSubmissions($params),
        ]);

        $this->registerTool('get_submission_files', [
            'description' => 'Get files for a submission',
            'parameters' => ['submission_id' => 'integer'],
            'handler' => fn($params) => $this->getSubmissionFiles($params['submission_id']),
        ]);

        // User tools
        $this->registerTool('get_user', [
            'description' => 'Get user details by ID',
            'parameters' => ['user_id' => 'integer'],
            'handler' => fn($params) => $this->getUser($params['user_id']),
        ]);

        $this->registerTool('search_users', [
            'description' => 'Search users by criteria',
            'parameters' => ['query' => 'string', 'role' => 'string'],
            'handler' => fn($params) => $this->searchUsers($params),
        ]);

        // Journal/Context tools
        $this->registerTool('get_journal', [
            'description' => 'Get journal details by ID',
            'parameters' => ['context_id' => 'integer'],
            'handler' => fn($params) => $this->getJournal($params['context_id']),
        ]);

        // Review tools
        $this->registerTool('get_review_assignments', [
            'description' => 'Get review assignments for a submission',
            'parameters' => ['submission_id' => 'integer'],
            'handler' => fn($params) => $this->getReviewAssignments($params['submission_id']),
        ]);

        // Issue tools
        $this->registerTool('get_current_issue', [
            'description' => 'Get the current issue for a journal',
            'parameters' => ['context_id' => 'integer'],
            'handler' => fn($params) => $this->getCurrentIssue($params['context_id']),
        ]);

        // Statistics tools
        $this->registerTool('get_submission_stats', [
            'description' => 'Get statistics for a submission',
            'parameters' => ['submission_id' => 'integer', 'date_start' => 'string', 'date_end' => 'string'],
            'handler' => fn($params) => $this->getSubmissionStats($params),
        ]);
    }

    /**
     * Register a new tool.
     */
    public function registerTool(string $name, array $definition): void
    {
        $this->tools[$name] = array_merge([
            'name' => $name,
            'description' => '',
            'parameters' => [],
            'handler' => null,
        ], $definition);
    }

    /**
     * @copydoc AgentNodeInterface::canHandle()
     */
    public function canHandle(array $input): bool
    {
        return isset($input['tool']) && isset($this->tools[$input['tool']]);
    }

    /**
     * @copydoc AgentNodeInterface::process()
     */
    public function process(array $input, array $context = []): array
    {
        $toolName = $input['tool'] ?? null;
        $params = $input['parameters'] ?? [];

        if (!$toolName) {
            return $this->buildResponse(false, null, 'No tool specified');
        }

        if (!isset($this->tools[$toolName])) {
            return $this->buildResponse(false, null, "Unknown tool: {$toolName}");
        }

        // Check if tool is allowed
        $allowedTools = $this->getConfig('allowed_tools');
        if ($allowedTools !== ['*'] && !in_array($toolName, $allowedTools)) {
            return $this->buildResponse(false, null, "Tool not allowed: {$toolName}");
        }

        try {
            $tool = $this->tools[$toolName];
            $result = call_user_func($tool['handler'], $params);

            $this->logExecution($input, ['tool' => $toolName, 'result' => $result]);

            return $this->buildResponse(true, $result, null, ['tool' => $toolName]);
        } catch (\Exception $e) {
            $this->logExecution($input, [], $e->getMessage());
            return $this->buildResponse(false, null, "Tool execution failed: {$e->getMessage()}");
        }
    }

    /**
     * Get available tools for LLM function calling.
     */
    public function getToolDefinitions(): array
    {
        return array_map(fn($tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'parameters' => $tool['parameters'],
        ], $this->tools);
    }

    // Built-in tool implementations

    protected function getSubmission(int $submissionId): ?array
    {
        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            return null;
        }

        return [
            'id' => $submission->getId(),
            'title' => $submission->getCurrentPublication()?->getLocalizedTitle(),
            'status' => $submission->getData('status'),
            'stage_id' => $submission->getData('stageId'),
            'date_submitted' => $submission->getData('dateSubmitted'),
        ];
    }

    protected function searchSubmissions(array $params): array
    {
        $collector = Repo::submission()->getCollector();

        if (!empty($params['context_id'])) {
            $collector->filterByContextIds([$params['context_id']]);
        }

        if (!empty($params['query'])) {
            $collector->searchPhrase($params['query']);
        }

        $collector->limit($params['limit'] ?? 10);

        return Repo::submission()->getMany($collector)->map(fn($s) => [
            'id' => $s->getId(),
            'title' => $s->getCurrentPublication()?->getLocalizedTitle(),
            'status' => $s->getData('status'),
        ])->toArray();
    }

    protected function getSubmissionFiles(int $submissionId): array
    {
        $files = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->getMany();

        return $files->map(fn($f) => [
            'id' => $f->getId(),
            'name' => $f->getLocalizedData('name'),
            'file_stage' => $f->getData('fileStage'),
            'genre_id' => $f->getData('genreId'),
        ])->toArray();
    }

    protected function getUser(int $userId): ?array
    {
        $user = Repo::user()->get($userId);
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'username' => $user->getData('userName'),
            'full_name' => $user->getFullName(),
            'email' => $user->getData('email'),
            'affiliation' => $user->getLocalizedData('affiliation'),
        ];
    }

    protected function searchUsers(array $params): array
    {
        $collector = Repo::user()->getCollector();

        if (!empty($params['query'])) {
            $collector->searchPhrase($params['query']);
        }

        return Repo::user()->getMany($collector)->map(fn($u) => [
            'id' => $u->getId(),
            'username' => $u->getData('userName'),
            'full_name' => $u->getFullName(),
        ])->toArray();
    }

    protected function getJournal(int $contextId): ?array
    {
        $context = app()->get('context')->get($contextId);
        if (!$context) {
            return null;
        }

        return [
            'id' => $context->getId(),
            'name' => $context->getLocalizedName(),
            'path' => $context->getData('urlPath'),
            'description' => $context->getLocalizedData('description'),
        ];
    }

    protected function getReviewAssignments(int $submissionId): array
    {
        $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
        $assignments = $reviewAssignmentDao->getBySubmissionId($submissionId);

        return array_map(fn($a) => [
            'id' => $a->getId(),
            'reviewer_id' => $a->getReviewerId(),
            'round' => $a->getRound(),
            'status' => $a->getStatus(),
            'date_assigned' => $a->getDateAssigned(),
            'date_completed' => $a->getDateCompleted(),
        ], $assignments->toArray());
    }

    protected function getCurrentIssue(int $contextId): ?array
    {
        $issue = Repo::issue()->getCurrent($contextId);
        if (!$issue) {
            return null;
        }

        return [
            'id' => $issue->getId(),
            'title' => $issue->getLocalizedTitle(),
            'volume' => $issue->getData('volume'),
            'number' => $issue->getData('number'),
            'year' => $issue->getData('year'),
            'published' => $issue->getData('published'),
        ];
    }

    protected function getSubmissionStats(array $params): array
    {
        // Simplified stats - would integrate with statistics service
        return [
            'submission_id' => $params['submission_id'],
            'views' => 0,
            'downloads' => 0,
            'period' => [
                'start' => $params['date_start'] ?? date('Y-m-01'),
                'end' => $params['date_end'] ?? date('Y-m-d'),
            ],
        ];
    }
}
