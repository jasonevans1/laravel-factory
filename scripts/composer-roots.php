<?php
// Usage: composer-roots.php <package> [composer.json] [composer.lock]
// Prints how the root project depends on <package>:
//   "require" | "require-dev"            - it is a direct dependency
//   "transitive <root> [<root> ...]"      - root requirements whose tree reaches it
//   "absent"                              - not in the lock file at all
// Deterministic helper for security-fix.sh: transitive packages are fixed by
// updating the direct dependencies that pull them in, never by adding a new
// root requirement.

$package = strtolower($argv[1] ?? '');
if ($package === '') {
    fwrite(STDERR, "usage: composer-roots.php <package> [composer.json] [composer.lock]\n");
    exit(2);
}
$json = json_decode(file_get_contents($argv[2] ?? 'composer.json'), true);
$lock = json_decode(file_get_contents($argv[3] ?? 'composer.lock'), true);

$lower = fn (array $a) => array_change_key_case($a, CASE_LOWER);
$require = $lower($json['require'] ?? []);
$requireDev = $lower($json['require-dev'] ?? []);

if (isset($require[$package])) {
    echo "require\n";
    exit(0);
}
if (isset($requireDev[$package])) {
    echo "require-dev\n";
    exit(0);
}

// package name => list of package names it requires
$graph = [];
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
    $graph[strtolower($p['name'])] = array_keys($lower($p['require'] ?? []));
}
if (! isset($graph[$package])) {
    echo "absent\n";
    exit(0);
}

$reaches = function (string $from) use ($graph, $package): bool {
    $seen = [];
    $stack = [$from];
    while ($stack) {
        $name = array_pop($stack);
        if ($name === $package) {
            return true;
        }
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        array_push($stack, ...($graph[$name] ?? []));
    }

    return false;
};

$roots = array_values(array_filter(
    array_keys($require + $requireDev),
    fn ($root) => isset($graph[$root]) && $reaches($root),
));
sort($roots);
echo 'transitive '.implode(' ', $roots)."\n";
