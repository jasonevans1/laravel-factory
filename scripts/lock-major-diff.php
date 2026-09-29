<?php
// Usage: lock-major-diff.php <old composer.lock> <new composer.lock>
// Lists every package whose major version changed between two lock files and
// exits 1 if there is any. For 0.x packages the minor is the breaking part, so
// 0.4 -> 0.5 counts as a major change. Dev branches (dev-main) are skipped.

function load(string $file): array
{
    $lock = json_decode((string) @file_get_contents($file), true);
    if (! is_array($lock)) {
        fwrite(STDERR, "cannot read lock file: $file\n");
        exit(2);
    }
    $versions = [];
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
        $versions[strtolower($p['name'])] = $p['version'];
    }

    return $versions;
}

function major(string $version): ?string
{
    if (! preg_match('/^v?(\d+)\.(\d+)/', $version, $m)) {
        return null;
    }

    return $m[1] === '0' ? "0.$m[2]" : $m[1];
}

$old = load($argv[1] ?? '');
$new = load($argv[2] ?? '');

$crossed = [];
foreach ($new as $name => $version) {
    $from = isset($old[$name]) ? major($old[$name]) : null;
    $to = major($version);
    if ($from !== null && $to !== null && $from !== $to) {
        $crossed[] = "$name {$old[$name]} -> $version";
    }
}

foreach ($crossed as $line) {
    echo $line."\n";
}
exit($crossed ? 1 : 0);
