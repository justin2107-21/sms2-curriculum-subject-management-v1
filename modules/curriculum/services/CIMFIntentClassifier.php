<?php

final class CIMFIntentClassifier
{
    public const GREETING = 'GREETING';
    public const CAPABILITY = 'CAPABILITY';
    public const VAGUE_CURRICULUM = 'VAGUE_CURRICULUM';
    public const SPECIFIC_CURRICULUM = 'SPECIFIC_CURRICULUM';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';

    public static function classify(string $question): string
    {
        $q = mb_strtolower(trim(preg_replace('/[^\pL\pN ]/u', ' ', $question) ?? ''));
        $q = trim(preg_replace('/\s+/', ' ', $q) ?? $q);
        if (preg_match('/^(hello|hi|hey)( cimf)?( there)?( everyone)?$/', $q)
            || preg_match('/^good (morning|afternoon|evening)( cimf)?$/', $q)) return self::GREETING;
        if (preg_match('/\b(what can you do|how can you help|what is cimf|what does cimf do|your capabilities)\b/', $q)) return self::CAPABILITY;
        if (preg_match('/\b(weather|basketball|football|soccer|baseball|game score|write me a poem|tell me a joke|news headlines)\b/', $q)) return self::OUT_OF_SCOPE;
        if (preg_match('/\b(subject|curriculum|prerequisite|prerequisites|sequence|sequencing|mapping|relationship|compare|review|stored|cap\d|cc\d|bsit|program)\b/i', $question)) {
            if (preg_match('/^(what do you think|any thoughts|thoughts|is this okay|review this|what do you think about this)$/', $q)) return self::VAGUE_CURRICULUM;
            return self::SPECIFIC_CURRICULUM;
        }
        return self::VAGUE_CURRICULUM;
    }
}
