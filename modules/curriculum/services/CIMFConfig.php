<?php

final class CIMFConfig
{
    public static function load(): array
    {
        $flag = strtolower(trim((string)(getenv('CIMF_AI_ENABLED') ?: 'false')));
        $provider = strtolower(trim((string)(getenv('CIMF_AI_PROVIDER') ?: '')));
        $model = trim((string)(getenv('CIMF_AI_MODEL') ?: ''));
        $specificModel = $provider === 'groq' ? trim((string)(getenv('CIMF_GROQ_MODEL') ?: ''))
            : ($provider === 'openai' ? trim((string)(getenv('CIMF_OPENAI_MODEL') ?: '')) : '');
        if ($specificModel !== '') $model = $specificModel;
        $key = $provider === 'groq' ? trim((string)(getenv('GROQ_API_KEY') ?: ''))
            : ($provider === 'openai' ? trim((string)(getenv('OPENAI_API_KEY') ?: '')) : '');
        return [
            'enabled' => in_array($flag, ['1', 'true', 'yes', 'on'], true),
            'provider' => $provider, 'api_key' => $key, 'model' => $model,
        ];
    }

    public static function status(array $config): string
    {
        if (!$config['enabled']) return 'AI_DISABLED';
        if (($config['provider'] ?? '') === '') return 'MISSING_PROVIDER';
        if (!in_array($config['provider'], ['groq', 'openai'], true)) return 'UNSUPPORTED_PROVIDER';
        if (($config['api_key'] ?? '') === '') return 'MISSING_API_KEY';
        if (($config['model'] ?? '') === '') return 'MISSING_MODEL';
        return 'SUCCESS';
    }

    public static function message(string $status): string
    {
        return match ($status) {
            'CONFIG_DISABLED', 'AI_DISABLED' => 'CIMF is currently disabled. Existing curriculum functions remain available.',
            'INSUFFICIENT_QUOTA' => 'CIMF API usage is currently unavailable. Please check the configured provider account.',
            'RATE_LIMITED' => 'CIMF has reached the provider request limit. Please try again later.',
            'TIMEOUT' => 'CIMF took too long to respond. Please try again.',
            'NETWORK_ERROR' => 'CIMF could not reach the configured provider. Please try again later.',
            'INVALID_RESPONSE' => 'CIMF returned a response that could not be validated. Please try again.',
            'AUTH_ERROR', 'AUTH_FAILED', 'CONFIG_MISSING_KEY', 'CONFIG_MISSING_MODEL', 'MISSING_PROVIDER', 'UNSUPPORTED_PROVIDER', 'MISSING_API_KEY', 'MISSING_MODEL' => 'CIMF is unavailable due to its server configuration. Existing curriculum functions remain available.',
            default => 'CIMF is temporarily unavailable. Existing curriculum functions remain available.',
        };
    }
}
