<?php

final class OpenAIClient
{
    public function request(array $config, array $prompt): array
    {
        if (!function_exists('curl_init')) return ['status' => 'NETWORK_ERROR'];
        $payload = json_encode(['model' => $config['model']] + $prompt, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) return ['status' => 'INVALID_RESPONSE'];
        $curl = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['api_key'], 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false) return ['status' => $errno === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'NETWORK_ERROR'];
        $data = json_decode($body, true);
        if ($http < 200 || $http >= 300) {
            $code = is_array($data) ? ($data['error']['code'] ?? '') : '';
            $status = match (true) {
                $http === 401 || $http === 403 => 'AUTH_ERROR',
                $http === 429 && $code === 'insufficient_quota' => 'INSUFFICIENT_QUOTA',
                $http === 429 => 'RATE_LIMITED',
                $http >= 500 => 'OPENAI_SERVER_ERROR',
                default => 'OPENAI_SERVER_ERROR',
            };
            return ['status' => $status, 'http_status' => $http];
        }
        if (!is_array($data) || ($data['status'] ?? '') !== 'completed') return ['status' => 'INVALID_RESPONSE', 'http_status' => $http];
        $texts = [];
        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') continue;
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) $texts[] = $part['text'];
            }
        }
        if (count($texts) !== 1) return ['status' => 'INVALID_RESPONSE', 'http_status' => $http];
        return ['status' => 'SUCCESS', 'body' => $texts[0], 'usage' => $data['usage'] ?? [], 'http_status' => $http];
    }
}
