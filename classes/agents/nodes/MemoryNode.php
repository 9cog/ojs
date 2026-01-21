<?php

/**
 * @file classes/agents/nodes/MemoryNode.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MemoryNode
 *
 * @brief Memory Node - Maintains stateful context over time.
 *
 * This node manages memory and context persistence including:
 * - Short-term conversation memory
 * - Long-term knowledge storage
 * - User preferences and history
 * - Session state management
 * - Vector embeddings for semantic search (RAG)
 */

namespace APP\agents\nodes;

use PKP\db\DAORegistry;
use PKP\config\Config;

class MemoryNode extends BaseAgentNode
{
    public const NODE_TYPE = 'memory';

    /** @var array Short-term memory (current session) */
    protected array $shortTermMemory = [];

    /** @var int Maximum short-term memory entries */
    protected int $maxShortTermEntries = 100;

    /** @var string Current session ID */
    protected string $sessionId;

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
            'storage_backend' => 'database', // database, redis, file
            'max_conversation_turns' => 50,
            'enable_embeddings' => false,
            'embedding_model' => 'text-embedding-3-small',
            'similarity_threshold' => 0.7,
        ]);
    }

    /**
     * Constructor.
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->sessionId = $config['session_id'] ?? uniqid('session_', true);
    }

    /**
     * @copydoc AgentNodeInterface::canHandle()
     */
    public function canHandle(array $input): bool
    {
        return isset($input['memory_action']) || isset($input['store']) || isset($input['retrieve']);
    }

    /**
     * @copydoc AgentNodeInterface::process()
     */
    public function process(array $input, array $context = []): array
    {
        $action = $input['memory_action'] ?? 'auto';

        try {
            $result = match ($action) {
                'store' => $this->store($input['key'] ?? null, $input['value'] ?? null, $input['metadata'] ?? []),
                'retrieve' => $this->retrieve($input['key'] ?? null, $input['query'] ?? null),
                'search' => $this->semanticSearch($input['query'] ?? '', $input['limit'] ?? 5),
                'list' => $this->listMemories($input['filter'] ?? []),
                'delete' => $this->delete($input['key'] ?? null),
                'clear' => $this->clearSession(),
                'summarize' => $this->summarizeConversation($input['messages'] ?? []),
                'auto' => $this->autoProcess($input, $context),
                default => throw new \InvalidArgumentException("Unknown memory action: {$action}"),
            };

            $this->logExecution($input, $result);
            return $this->buildResponse(true, $result);
        } catch (\Exception $e) {
            $this->logExecution($input, [], $e->getMessage());
            return $this->buildResponse(false, null, $e->getMessage());
        }
    }

    /**
     * Store a memory entry.
     */
    public function store(?string $key, mixed $value, array $metadata = []): array
    {
        $key = $key ?? uniqid('mem_', true);
        $timestamp = date('c');

        $entry = [
            'key' => $key,
            'value' => $value,
            'metadata' => array_merge($metadata, [
                'session_id' => $this->sessionId,
                'timestamp' => $timestamp,
                'type' => $metadata['type'] ?? 'general',
            ]),
        ];

        // Store in short-term memory
        $this->shortTermMemory[$key] = $entry;

        // Prune if exceeds max
        if (count($this->shortTermMemory) > $this->maxShortTermEntries) {
            array_shift($this->shortTermMemory);
        }

        // Persist to long-term storage
        if ($metadata['persist'] ?? false) {
            $this->persistToStorage($entry);
        }

        // Generate embedding if enabled
        if ($this->getConfig('enable_embeddings') && is_string($value)) {
            $entry['embedding'] = $this->generateEmbedding($value);
        }

        return [
            'action' => 'store',
            'key' => $key,
            'stored' => true,
            'persisted' => $metadata['persist'] ?? false,
        ];
    }

    /**
     * Retrieve a memory entry.
     */
    public function retrieve(?string $key, ?string $query = null): array
    {
        // Direct key lookup
        if ($key !== null) {
            $entry = $this->shortTermMemory[$key] ?? $this->retrieveFromStorage($key);

            return [
                'action' => 'retrieve',
                'key' => $key,
                'found' => $entry !== null,
                'value' => $entry['value'] ?? null,
                'metadata' => $entry['metadata'] ?? null,
            ];
        }

        // Query-based retrieval
        if ($query !== null) {
            return $this->semanticSearch($query, 1);
        }

        return [
            'action' => 'retrieve',
            'found' => false,
            'message' => 'No key or query provided',
        ];
    }

    /**
     * Semantic search through memories.
     */
    public function semanticSearch(string $query, int $limit = 5): array
    {
        if (!$this->getConfig('enable_embeddings')) {
            // Fallback to keyword search
            return $this->keywordSearch($query, $limit);
        }

        $queryEmbedding = $this->generateEmbedding($query);
        $results = [];

        // Search short-term memory
        foreach ($this->shortTermMemory as $entry) {
            if (!isset($entry['embedding'])) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryEmbedding, $entry['embedding']);

            if ($similarity >= $this->getConfig('similarity_threshold')) {
                $results[] = [
                    'key' => $entry['key'],
                    'value' => $entry['value'],
                    'similarity' => $similarity,
                    'metadata' => $entry['metadata'],
                ];
            }
        }

        // Sort by similarity
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return [
            'action' => 'search',
            'query' => $query,
            'results' => array_slice($results, 0, $limit),
            'total_found' => count($results),
        ];
    }

    /**
     * Keyword-based search fallback.
     */
    protected function keywordSearch(string $query, int $limit): array
    {
        $keywords = array_filter(explode(' ', strtolower($query)));
        $results = [];

        foreach ($this->shortTermMemory as $entry) {
            $text = strtolower(json_encode($entry['value']));
            $matches = 0;

            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $matches++;
                }
            }

            if ($matches > 0) {
                $results[] = [
                    'key' => $entry['key'],
                    'value' => $entry['value'],
                    'relevance' => $matches / count($keywords),
                    'metadata' => $entry['metadata'],
                ];
            }
        }

        usort($results, fn($a, $b) => $b['relevance'] <=> $a['relevance']);

        return [
            'action' => 'search',
            'query' => $query,
            'method' => 'keyword',
            'results' => array_slice($results, 0, $limit),
        ];
    }

    /**
     * List memories with optional filter.
     */
    public function listMemories(array $filter = []): array
    {
        $memories = $this->shortTermMemory;

        // Apply filters
        if (!empty($filter['type'])) {
            $memories = array_filter($memories, fn($m) => ($m['metadata']['type'] ?? '') === $filter['type']);
        }

        if (!empty($filter['session_id'])) {
            $memories = array_filter($memories, fn($m) => ($m['metadata']['session_id'] ?? '') === $filter['session_id']);
        }

        return [
            'action' => 'list',
            'count' => count($memories),
            'memories' => array_values($memories),
            'filter' => $filter,
        ];
    }

    /**
     * Delete a memory entry.
     */
    public function delete(?string $key): array
    {
        if ($key === null) {
            return ['action' => 'delete', 'deleted' => false, 'message' => 'No key provided'];
        }

        $existed = isset($this->shortTermMemory[$key]);
        unset($this->shortTermMemory[$key]);

        return [
            'action' => 'delete',
            'key' => $key,
            'deleted' => $existed,
        ];
    }

    /**
     * Clear current session memories.
     */
    public function clearSession(): array
    {
        $count = count($this->shortTermMemory);
        $this->shortTermMemory = [];

        return [
            'action' => 'clear',
            'cleared_count' => $count,
            'session_id' => $this->sessionId,
        ];
    }

    /**
     * Summarize conversation for context compression.
     */
    public function summarizeConversation(array $messages): array
    {
        // Build conversation text
        $text = '';
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';
            $text .= "[{$role}]: {$content}\n";
        }

        // Store the summary as a compressed memory
        $summary = [
            'type' => 'conversation_summary',
            'message_count' => count($messages),
            'text' => $text,
            'timestamp' => date('c'),
        ];

        $this->store('conversation_' . date('Ymd_His'), $summary, [
            'type' => 'summary',
            'persist' => true,
        ]);

        return [
            'action' => 'summarize',
            'message_count' => count($messages),
            'summary_stored' => true,
        ];
    }

    /**
     * Auto-process based on input content.
     */
    protected function autoProcess(array $input, array $context): array
    {
        // If there's content to store
        if (isset($input['store'])) {
            return $this->store($input['key'] ?? null, $input['store'], $input['metadata'] ?? []);
        }

        // If there's a retrieval request
        if (isset($input['retrieve'])) {
            return $this->retrieve($input['retrieve']);
        }

        // Default: return current context
        return [
            'action' => 'context',
            'session_id' => $this->sessionId,
            'memory_count' => count($this->shortTermMemory),
            'recent' => array_slice(array_values($this->shortTermMemory), -5),
        ];
    }

    /**
     * Generate embedding vector for text.
     */
    protected function generateEmbedding(string $text): array
    {
        $apiKey = Config::getVar('agents', 'openai_api_key', getenv('OPENAI_API_KEY'));

        if (!$apiKey) {
            // Return simple hash-based pseudo-embedding
            return $this->simpleEmbedding($text);
        }

        // Call OpenAI embeddings API
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->getConfig('embedding_model'),
                'input' => $text,
            ]),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$apiKey}",
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        return $data['data'][0]['embedding'] ?? $this->simpleEmbedding($text);
    }

    /**
     * Simple hash-based embedding fallback.
     */
    protected function simpleEmbedding(string $text): array
    {
        $words = str_word_count(strtolower($text), 1);
        $embedding = array_fill(0, 64, 0.0);

        foreach ($words as $i => $word) {
            $hash = crc32($word);
            $idx = abs($hash) % 64;
            $embedding[$idx] += 1.0 / (1 + $i);
        }

        // Normalize
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $embedding)));
        if ($magnitude > 0) {
            $embedding = array_map(fn($x) => $x / $magnitude, $embedding);
        }

        return $embedding;
    }

    /**
     * Calculate cosine similarity between two vectors.
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }

        $magnitude = sqrt($magnitudeA) * sqrt($magnitudeB);

        return $magnitude > 0 ? $dotProduct / $magnitude : 0.0;
    }

    /**
     * Persist entry to long-term storage.
     */
    protected function persistToStorage(array $entry): void
    {
        // TODO: Implement database persistence
        // This would use OJS's database layer
    }

    /**
     * Retrieve entry from long-term storage.
     */
    protected function retrieveFromStorage(string $key): ?array
    {
        // TODO: Implement database retrieval
        return null;
    }

    /**
     * Get session ID.
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * @copydoc BaseAgentNode::reset()
     */
    public function reset(): void
    {
        parent::reset();
        $this->shortTermMemory = [];
    }
}
