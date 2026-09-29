<?php
// Usage: larastan-ratchet-check.php <old baseline> <new baseline>
// The ratchet: the baseline may only shrink. Fails (exit 1) on any new or
// increased entry, or when the total did not go down. The agent's claim that it
// "fixed errors" never counts; only this comparison does.

function load(string $file): array
{
    if (! is_file($file)) {
        return []; // no baseline = zero entries
    }
    $root = dirname(realpath($file)).'/';
    $map = [];
    foreach ((require $file)['parameters']['ignoreErrors'] ?? [] as $e) {
        $path = str_starts_with($e['path'], $root) ? substr($e['path'], strlen($root)) : $e['path'];
        $key = $path.'|'.($e['identifier'] ?? '').'|'.$e['message'];
        $map[$key] = ($map[$key] ?? 0) + $e['count'];
    }

    return $map;
}

if ($argc < 3) {
    fwrite(STDERR, "usage: larastan-ratchet-check.php <old baseline> <new baseline>\n");
    exit(2);
}
$old = load($argv[1]);
$new = load($argv[2]);

$failed = false;
foreach ($new as $key => $count) {
    if ($count > ($old[$key] ?? 0)) {
        fwrite(STDERR, "New or increased baseline entry: $key\n");
        $failed = true;
    }
}
[$before, $after] = [array_sum($old), array_sum($new)];
if ($after >= $before) {
    fwrite(STDERR, "Baseline did not shrink ($before -> $after).\n");
    $failed = true;
}

echo "Baseline: $before -> $after\n";
exit($failed ? 1 : 0);
