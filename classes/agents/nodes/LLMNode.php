<?php

/**
 * @file classes/agents/nodes/LLMNode.php
 *
 * Copyright (c) 2024 Public Knowledge Project
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LLMNode
 *
 * @brief LLM Node - The reasoning and decision-making brain of the agent system.
 *
 * This node handles all AI reasoning tasks including:
 * - Natural language understanding and generation
 * - Decision making and planning
 * - Content analysis and summarization
 * - Multi-turn conversation management
 *
 * Supports multiple LLM providers (OpenAI, Anthropic, local models).
 */

namespace APP\agents\nodes;

use PKP\config\Config;

class LLMNode extends BaseAgentNode
{
    public const NODE_TYPE = 'llm';

    /** @var string Current provider */
    protected string $provider;

    /** @var array Conversation history for multi-turn */
    protected array $conversationHistory = [];

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
            'provider' => 'openai', // openai, anthropic, ollama
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'system_prompt' => 'You are a helpful assistant for Open Journal Systems, specializing in academic publishing workflows.',
            'streaming' => false,
        ]);
    }

    /**
     * @copydoc AgentNodeInterface::canHandle()
     */
    public function canHandle(array $input): bool
    {
        return isset($input['prompt']) || isset($input['messages']);
    }

    /**
     * @copydoc AgentNodeInterface::process()
     */
    public function process(array $input, array $context = []): array
    {
        try {
            $messages = $this->buildMessages($input, $context);
            $response = $this->callLLM($messages);

            // Update conversation history
            if ($this->getConfig('maintain_history', true)) {
                $this->conversationHistory = array_merge(
                    $this->conversationHistory,
                    $messages,
                    [['role' => 'assistant', 'content' => $response['content']]]
                );
            }

            $this->logExecution($input, $response);

            return $this->buildResponse(true, [
                'content' => $response['content'],
                'model' => $response['model'] ?? $this->getConfig('model'),
                'usage' => $response['usage'] ?? null,
                'finish_reason' => $response['finish_reason'] ?? 'stop',
            ]);
        } catch (\Exception $e) {
            $this->logExecution($input, [], $e->getMessage());
            return $this->buildResponse(false, null, $e->getMessage());
        }
    }

    /**
     * Build messages array for the LLM.
     */
    protected function buildMessages(array $input, array $context): array
    {
        $messages = [];

        // Add system prompt
        $systemPrompt = $input['system_prompt'] ?? $this->getConfig('system_prompt');
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Add context from memory node if available
        if (!empty($context['memory'])) {
            $messages[] = [
                'role' => 'system',
                'content' => "Previous context:\n" . json_encode($context['memory'], JSON_PRETTY_PRINT),
            ];
        }

        // Add conversation history
        if ($this->getConfig('maintain_history', true)) {
            $messages = array_merge($messages, $this->conversationHistory);
        }

        // Add current message(s)
        if (isset($input['messages'])) {
            $messages = array_merge($messages, $input['messages']);
        } elseif (isset($input['prompt'])) {
            $messages[] = ['role' => 'user', 'content' => $input['prompt']];
        }

        return $messages;
    }

    /**
     * Call the LLM API.
     */
    protected function callLLM(array $messages): array
    {
        $provider = $this->getConfig('provider');

        return match ($provider) {
            'openai' => $this->callOpenAI($messages),
            'anthropic' => $this->callAnthropic($messages),
            'ollama' => $this->callOllama($messages),
            default => throw new \InvalidArgumentException("Unknown LLM provider: {$provider}"),
        };
    }

    /**
     * Call OpenAI API.
     */
    protected function callOpenAI(array $messages): array
    {
        $apiKey = Config::getVar('agents', 'openai_api_key', getenv('OPENAI_API_KEY'));
        if (!$apiKey) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        $payload = [
            'model' => $this->getConfig('model'),
            'messages' => $messages,
            'temperature' => $this->getConfig('temperature'),
            'max_tokens' => $this->getConfig('max_tokens'),
        ];

        $response = $this->httpPost('https://api.openai.com/v1/chat/completions', $payload, [
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ]);

        return [
            'content' => $response['choices'][0]['message']['content'] ?? '',
            'model' => $response['model'] ?? $this->getConfig('model'),
            'usage' => $response['usage'] ?? null,
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? 'stop',
        ];
    }

    /**
     * Call Anthropic API.
     */
    protected function callAnthropic(array $messages): array
    {
        $apiKey = Config::getVar('agents', 'anthropic_api_key', getenv('ANTHROPIC_API_KEY'));
        if (!$apiKey) {
            throw new \RuntimeException('Anthropic API key not configured');
        }

        // Extract system message
        $system = '';
        $filteredMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system .= $msg['content'] . "\n";
            } else {
                $filteredMessages[] = $msg;
            }
        }

        $payload = [
            'model' => $this->getConfig('model', 'claude-3-5-sonnet-20241022'),
            'max_tokens' => $this->getConfig('max_tokens'),
            'system' => trim($system),
            'messages' => $filteredMessages,
        ];

        $response = $this->httpPost('https://api.anthropic.com/v1/messages', $payload, [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ]);

        return [
            'content' => $response['content'][0]['text'] ?? '',
            'model' => $response['model'] ?? $this->getConfig('model'),
            'usage' => [
                'input_tokens' => $response['usage']['input_tokens'] ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
            ],
            'finish_reason' => $response['stop_reason'] ?? 'end_turn',
        ];
    }

    /**
     * Call Ollama (local) API.
     */
    protected function callOllama(array $messages): array
    {
        $baseUrl = Config::getVar('agents', 'ollama_base_url', 'http://localhost:11434');

        $payload = [
            'model' => $this->getConfig('model', 'llama3.2'),
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $this->getConfig('temperature'),
                'num_predict' => $this->getConfig('max_tokens'),
            ],
        ];

        $response = $this->httpPost("{$baseUrl}/api/chat", $payload, [
            'Content-Type' => 'application/json',
        ]);

        return [
            'content' => $response['message']['content'] ?? '',
            'model' => $response['model'] ?? $this->getConfig('model'),
            'usage' => [
                'prompt_tokens' => $response['prompt_eval_count'] ?? 0,
                'completion_tokens' => $response['eval_count'] ?? 0,
            ],
            'finish_reason' => $response['done'] ? 'stop' : 'length',
        ];
    }

    /**
     * Make HTTP POST request.
     */
    protected function httpPost(string $url, array $data, array $headers = []): array
    {
        $ch = curl_init($url);

        $headerList = [];
        foreach ($headers as $key => $value) {
            $headerList[] = "{$key}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->getConfig('timeout'),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("HTTP request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("HTTP error {$httpCode}: {$response}");
        }

        return json_decode($response, true) ?? [];
    }

    /**
     * Clear conversation history.
     */
    public function clearHistory(): void
    {
        $this->conversationHistory = [];
    }

    /**
     * @copydoc BaseAgentNode::reset()
     */
    public function reset(): void
    {
        parent::reset();
        $this->conversationHistory = [];
    }
}
