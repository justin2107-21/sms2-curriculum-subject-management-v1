<?php
require_once __DIR__ . '/AcademicSequenceValidator.php';

/** Structural checks only; no subject-code or academic-policy inference. */
class PrerequisiteGraph
{
    public static function complete(?array $placement): bool
    {
        return $placement && trim((string)($placement['year_level'] ?? '')) !== ''
            && trim((string)($placement['semester'] ?? '')) !== '';
    }

    public static function after(array $required, array $target): bool
    {
        $a = AcademicSequenceValidator::position($required);
        $b = AcademicSequenceValidator::position($target);
        return $a !== null && $b !== null && $a > $b;
    }

    public static function review(array $placements, array $edges): array
    {
        $map = []; $graph = []; $reverse = []; $pairs = [];
        $out = ['cycles' => [], 'duplicates' => [], 'sequencing' => [], 'missing' => []];
        foreach ($placements as $p) $map[(int)$p['subject_id']] = $p;
        foreach ($edges as $e) {
            $t = (int)$e['subject_id']; $r = (int)$e['required_subject_id'];
            $graph[$r][$t] = $t;
            $reverse[$t][$r] = $r;
            $key = "$r:$t";
            if (isset($pairs[$key])) $out['duplicates'][] = $e;
            $pairs[$key] = true;
            if (!self::complete($map[$t] ?? null) || !self::complete($map[$r] ?? null)) $out['missing'][] = $e;
            else {
                $result = AcademicSequenceValidator::validate($placements, [$e]);
                if (!$result['valid']) $out['sequencing'][] = $e + $result['conflicts'][0];
            }
        }
        // Enumerate directed simple cycles once, anchored at their smallest ID.
        $nodes = array_keys($graph); sort($nodes, SORT_NUMERIC);
        foreach ($nodes as $start) {
            // Prune paths that cannot return to this anchor (linear work on a DAG).
            $returns = []; $pending = [$start];
            while ($pending) {
                $node = array_pop($pending);
                foreach ($reverse[$node] ?? [] as $previous) if (!isset($returns[$previous])) {
                    $returns[$previous] = true; $pending[] = $previous;
                }
            }
            if (!isset($returns[$start])) continue;
            $walk = function ($node, $path, $seen) use (&$walk, &$out, $graph, $start, $returns) {
                foreach ($graph[$node] ?? [] as $next) {
                    if ($next === $start) $out['cycles'][] = array_merge($path, [$start]);
                    elseif ($next > $start && isset($returns[$next]) && !isset($seen[$next])) {
                        $visited = $seen; $visited[$next] = true;
                        $walk($next, array_merge($path, [$next]), $visited);
                    }
                }
            };
            $walk($start, [$start], [$start => true]);
        }
        return $out;
    }
}
