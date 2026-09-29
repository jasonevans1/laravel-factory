<?php
// Usage: prod-error-next.php <errors.json> [skip-file]
// Prod-error loop controller. Input is the app's error-tracker export,
// normalised by the app's sensor command to:
//   [{"id": "...", "exception_class": "...", "count": 12, "route": "/x/{id}",
//     "frames": [{"file": "app/...", "line": 10, "function": "..."}], ...}]
// Picks the highest-count group whose top frame is in app/, and prints a work
// order as JSON.
//
// Untrusted-input rule, enforced here rather than by prompt: only the
// whitelisted fields below reach the agent, each validated against a strict
// pattern. Messages, request payloads, user data and anything unrecognised are
// dropped. A field that fails validation drops the whole group.

const PATTERNS = [
    'id' => '/^[\w.:-]{1,64}$/',
    'exception_class' => '/^[A-Za-z_][\w\\\\]{0,200}$/',
    'route' => '/^\/[\w\/{}.-]{0,200}$/',
    'file' => '/^(app|routes|config|database|resources\/views)\/[\w\/.-]{1,200}\.php$/',
    'function' => '/^[\w\\\\:>{}-]{1,200}$/',
];

function valid(string $field, mixed $value): bool
{
    return is_string($value) && preg_match(PATTERNS[$field], $value) === 1;
}

function sanitise(array $group): ?array
{
    if (! valid('id', $group['id'] ?? null) || ! valid('exception_class', $group['exception_class'] ?? null)) {
        return null;
    }
    $route = $group['route'] ?? null;
    $frames = [];
    foreach (array_slice($group['frames'] ?? [], 0, 15) as $f) {
        if (! valid('file', $f['file'] ?? null) || ! is_int($f['line'] ?? null)
            || (isset($f['function']) && ! valid('function', $f['function']))) {
            continue; // vendor or malformed frame: dropped
        }
        $frames[] = ['file' => $f['file'], 'line' => $f['line'], 'function' => $f['function'] ?? null];
    }

    return [
        'id' => $group['id'],
        'exception_class' => $group['exception_class'],
        'count' => (int) ($group['count'] ?? 0),
        'route' => valid('route', $route) ? $route : null,
        'frames' => $frames,
    ];
}

$groups = json_decode((string) @file_get_contents($argv[1] ?? ''), true);
if (! is_array($groups)) {
    fwrite(STDERR, "usage: prod-error-next.php <errors.json> [skip-file]\n");
    exit(2);
}
$skip = isset($argv[2]) && is_file($argv[2]) ? array_map('trim', file($argv[2])) : [];

$candidates = [];
foreach ($groups as $group) {
    $clean = is_array($group) ? sanitise($group) : null;
    $top = $group['frames'][0]['file'] ?? '';
    if ($clean && str_starts_with((string) $top, 'app/') && $clean['frames'] && ! in_array($clean['id'], $skip, true)) {
        $candidates[] = $clean;
    }
}
usort($candidates, fn ($a, $b) => [$b['count'], $a['id']] <=> [$a['count'], $b['id']]);

echo json_encode($candidates[0] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
