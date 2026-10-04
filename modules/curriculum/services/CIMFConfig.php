<?php

final class CIMFConfig
{
    public static function load(): array
    {
        $flag = strtolower(trim((string)(getenv('CIMF_AI_ENABLED') ?: 'false')));
        return [
            'enabled' => in_array($flag, ['1', 'true', 'yes', 'on'], true),
            'api_key' => trim((string)(getenv('OPENAI_API_KEY') ?: '')),
            'model' => trim((string)(getenv('CIMF_OPENAI_MODEL') ?: '')),
        ];
    }

    public static function status(array $config): string
    {
        if (!$config['enabled']) return 'CONFIG_DISABLED';
        if ($config['api_key'] === '') return 'CONFIG_MISSING_KEY';
        if ($config['model'] === '') return 'CONFIG_MISSING_MODEL';
        return 'SUCCESS';
    }

    public static function message(string $status): string
    {
        return match ($status) {
            'CONFIG_DISABLED' => 'CIMF is currently disabled. Existing curriculum functions remain available.',
            'INSUFFICIENT_QUOTA' => "CIMF is configured, but OpenAI API usage is currently unavailable. Please check the API project's billing or available credits.",
            'AUTH_ERROR', 'CONFIG_MISSING_KEY', 'CONFIG_MISSING_MODEL' => 'CIMF is unavailable due to its server configuration. Existing curriculum functions remain available.',
            default => 'CIMF is temporarily unavailable. Existing curriculum functions remain available.',
        };
    }
}
