<?php

final class CIMFService
{
    public function __construct(private CIMFAIProviderInterface $provider) {}

    public static function forConfig(array $config): self
    {
        return new self(CIMFAIProviderFactory::create((string)($config['provider'] ?? '')));
    }

    public function analyze(array $config, array $prompt, array $allowed, string $action, array $context, string $question = ''): array
    {
        $result = $this->provider->request($config, $prompt);
        if (($result['status'] ?? '') !== 'SUCCESS') return $result;
        $normalized = CIMFResponseValidator::normalize((string)($result['body'] ?? ''), $allowed, $action, $context, $question);
        if ($normalized === null) return ['status' => 'INVALID_RESPONSE', 'http_status' => $result['http_status'] ?? 0,
            'usage' => $result['usage'] ?? [], 'diagnostic' => array_merge($result['diagnostic'] ?? [], ['category' => 'SCHEMA_VALIDATION_FAILED'])];
        $result['analysis'] = $normalized;
        unset($result['body']);
        return $result;
    }
}
