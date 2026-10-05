<?php

require_once __DIR__ . '/CIMFAIProviderInterface.php';

final class GroqProvider implements CIMFAIProviderInterface
{
    public function __construct(private $transport = null) {}

    public function request(array $config, array $prompt): array
    {
        $schema = $prompt['text']['format']['schema'] ?? null;
        $instructions = (string)($prompt['instructions'] ?? '');
        if (is_array($schema)) $instructions .= "\nReturn only JSON matching this schema:\n" . json_encode($schema, JSON_UNESCAPED_UNICODE);
        $payload = ['model' => $config['model'], 'temperature' => 0.1, 'reasoning_effort' => 'low', 'messages' => [
            ['role' => 'system', 'content' => $instructions],
            ['role' => 'user', 'content' => (string)($prompt['input'] ?? '')],
        ], 'response_format' => ['type' => 'json_object'], 'max_completion_tokens' => 3000];
        return $this->send('https://api.groq.com/openai/v1/chat/completions', $config, $payload);
    }

    private function send(string $url, array $config, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) return ['status' => 'INVALID_RESPONSE'];
        if ($this->transport !== null) $response = ($this->transport)($url, $config['api_key'], $json);
        else {
            if (!function_exists('curl_init')) return ['status' => 'NETWORK_ERROR'];
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['api_key'], 'Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($curl); $errno = curl_errno($curl); $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
            if ($body === false) return ['status' => $errno === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'NETWORK_ERROR'];
            $response = ['body' => $body, 'http_status' => $http];
        }
        if (!is_array($response)) return ['status' => 'NETWORK_ERROR'];
        $body = $response['body'] ?? false; $http = (int)($response['http_status'] ?? 0);
        if ($body === false) return ['status' => ($response['timeout'] ?? false) ? 'TIMEOUT' : 'NETWORK_ERROR'];
        $data = is_array($body) ? $body : json_decode((string)$body, true);
        if ($http < 200 || $http >= 300) {
            $providerError = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
            return ['status' => match (true) {
                $http === 401 || $http === 403 => 'AUTH_FAILED', $http === 429 => 'RATE_LIMITED',
                $http >= 500 => 'PROVIDER_ERROR', default => 'PROVIDER_ERROR',
            }, 'http_status' => $http, 'diagnostic' => ['category' => 'PROVIDER_HTTP_ERROR',
                'provider_code' => substr((string)($providerError['code'] ?? ''), 0, 80)]];
        }
        $text = $data['choices'][0]['message']['content'] ?? null;
        $finish = (string)($data['choices'][0]['finish_reason'] ?? '');
        if (!is_string($text) || json_decode($text, true) === null) return ['status' => 'INVALID_RESPONSE', 'http_status' => $http,
            'diagnostic' => ['category' => $finish === 'length' ? 'TRUNCATED_OUTPUT' : 'MALFORMED_JSON', 'finish_reason' => substr($finish, 0, 30)]];
        $usage = $data['usage'] ?? [];
        return ['status' => 'SUCCESS', 'body' => $text, 'http_status' => $http, 'usage' => [
            'input_tokens' => (int)($usage['prompt_tokens'] ?? 0), 'output_tokens' => (int)($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int)($usage['total_tokens'] ?? 0)], 'diagnostic' => ['finish_reason' => substr($finish, 0, 30)]];
    }
}
