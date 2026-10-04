<?php

final class CIMFResponseValidator
{
    public static function normalize(string $text, array $allowed): ?array
    {
        $data = json_decode($text, true);
        if (!is_array($data) || !is_string($data['summary'] ?? null)
            || !is_array($data['review_items'] ?? null) || !is_array($data['limitations'] ?? null)) return null;
        $items = [];
        foreach (array_slice($data['review_items'], 0, 20) as $item) {
            if (!is_array($item) || !is_int($item['subject_id'] ?? null) || !is_string($item['reason'] ?? null)) continue;
            $id = $item['subject_id'];
            $related = $item['related_subject_id'] ?? null;
            if (!isset($allowed[$id]) || ($related !== null && (!is_int($related) || !isset($allowed[$related])))) continue;
            if (($item['subject_code'] ?? '') !== $allowed[$id]['subject_code'] ||
                ($related !== null && ($item['related_subject_code'] ?? '') !== $allowed[$related]['subject_code'])) continue;
            $items[] = [
                'subject_id' => $id, 'subject_code' => $allowed[$id]['subject_code'],
                'subject_title' => $allowed[$id]['subject_title'],
                'finding_type' => in_array($item['finding_type'] ?? '', ['possible_relationship', 'possible_concern'], true) ? $item['finding_type'] : 'possible_concern',
                'related_subject_id' => $related,
                'related_subject_code' => $related === null ? null : $allowed[$related]['subject_code'],
                'reason' => self::short($item['reason'], 500), 'review_status' => 'needs_human_review',
            ];
        }
        return ['summary' => self::short($data['summary'], 1200), 'review_items' => $items,
            'limitations' => array_values(array_map(fn($s) => self::short($s, 300), array_filter(array_slice($data['limitations'], 0, 5), 'is_string')))];
    }

    private static function short(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }
}
