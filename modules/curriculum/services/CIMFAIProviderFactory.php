<?php

final class CIMFAIProviderFactory
{
    public static function create(string $provider): CIMFAIProviderInterface
    {
        return match ($provider) {
            'groq' => new GroqProvider(),
            'openai' => new OpenAIClient(),
            default => throw new InvalidArgumentException('Unsupported CIMF provider.'),
        };
    }
}
