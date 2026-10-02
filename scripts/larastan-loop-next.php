<?php
// Usage: larastan-loop-next.php [phpstan-baseline.php]
//        larastan-loop-next.php [phpstan-baseline.php] --errors-for <path>
// Larastan loop sensor + controller. Reads the PHP-format baseline and prints
//   Total: <n>
//   Next: <path>
// Files with deprecation errors (identifier *.deprecated) go first, then the
// file with the most baselined errors. Paths in FACTORY_SKIP (space-separated,
// from open deferral issues) are never picked. With --errors-for, lists that
// file's baselined errors instead (the actuator's work order).

$args = array_slice($argv, 1);
$errorsFor = null;
if (($i = array_search('--errors-for', $args, true)) !== false) {
    $errorsFor = $args[$i + 1] ?? null;
    array_splice($args, $i, 2);
}
$baseline = $args[0] ?? 'phpstan-baseline.php';

$entries = [];
if (is_file($baseline)) {
    $root = dirname(realpath($baseline)).'/';
    foreach ((require $baseline)['parameters']['ignoreErrors'] ?? [] as $e) {
        $e['path'] = str_starts_with($e['path'], $root) ? substr($e['path'], strlen($root)) : $e['path'];
        $entries[] = $e;
    }
}

if ($errorsFor !== null) {
    foreach ($entries as $e) {
        if ($e['path'] === $errorsFor) {
            $message = stripslashes(trim(preg_replace('/^#\^|\$#$/', '', $e['message'])));
            printf("- [%s] x%d %s\n", $e['identifier'] ?? '-', $e['count'], $message);
        }
    }
    exit(0);
}

$byPath = [];
foreach ($entries as $e) {
    $p = $e['path'];
    $byPath[$p] ??= ['count' => 0, 'deprecated' => false];
    $byPath[$p]['count'] += $e['count'];
    $byPath[$p]['deprecated'] = $byPath[$p]['deprecated'] || str_ends_with($e['identifier'] ?? '', '.deprecated');
}
uksort($byPath, fn ($a, $b) => [$byPath[$b]['deprecated'], $byPath[$b]['count'], $a]
    <=> [$byPath[$a]['deprecated'], $byPath[$a]['count'], $b]);

echo 'Total: '.array_sum(array_column($byPath, 'count'))."\n";
$skip = preg_split('/\s+/', (string) getenv('FACTORY_SKIP'), -1, PREG_SPLIT_NO_EMPTY);
$candidates = array_diff(array_keys($byPath), $skip);
if ($candidates) {
    echo 'Next: '.reset($candidates)."\n";
} elseif ($byPath) {
    echo "All remaining files are deferred.\n";
}
