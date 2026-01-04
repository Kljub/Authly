<?php
// ============================================================================
// PFAD: /functions/admin_functions/routes_helpers.php
// ============================================================================
function routes_is_valid_enum($v, array $allowed): bool {
    return in_array($v, $allowed, true);
}

function routes_norm_kind(string $kind): string {
    $k = strtolower(trim($kind));
    return in_array($k, ['title','group','item'], true) ? $k : 'item';
}

function routes_roles_csv_to_json(?string $csv): ?string {
    $csv = trim((string)$csv);
    if ($csv === '') return null;

    $first = substr($csv, 0, 1);
    if ($first === '[' || $first === '{') {
        $tmp = json_decode($csv, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
            return json_encode($tmp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return null;
    }

    $parts = array_filter(array_map('trim', explode(',', $csv)), fn($x) => $x !== '');
    if (empty($parts)) return null;

    $norm = [];
    foreach ($parts as $p) {
        if ($p === '1') $p = 'admin';
        if ($p === '2') $p = 'user';
        $norm[] = $p;
    }

    return json_encode(array_values(array_unique($norm)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function routes_roles_json_to_csv($json): string {
    if ($json === null || $json === '') return '';
    $arr = json_decode((string)$json, true);
    if (!is_array($arr)) return '';
    return implode(',', array_map('strval', $arr));
}

function routes_is_descendant(array $childrenMap, int $nodeId, int $candidateParentId): bool {
    if ($candidateParentId <= 0) return false;
    $stack = [$nodeId];
    $seen = [];
    while (!empty($stack)) {
        $cur = array_pop($stack);
        if (isset($seen[$cur])) continue;
        $seen[$cur] = true;
        foreach (($childrenMap[$cur] ?? []) as $ch) {
            if ($ch === $candidateParentId) return true;
            $stack[] = $ch;
        }
    }
    return false;
}
