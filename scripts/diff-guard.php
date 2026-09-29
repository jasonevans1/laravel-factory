<?php
// Usage: diff-guard.php --base <ref> [--allow <glob>]... [--deny <glob>]...
//                       [--deny-added <regex>]... [--flag-added <regex>]...
// Checks the committed diff <base>..HEAD, deterministically:
//   --allow       every changed file must match one of these globs (if any given)
//   --deny        no changed file may match these globs
//   --deny-added  no added line may match these regexes
//   --flag-added  added lines matching these are reported for review, not failed
// Globs use fnmatch without FNM_PATHNAME, so "tests/*" covers all of tests/.
// Exit 1 on any violation. Prints "FLAG: ..." lines and sets flagged=true|false
// in $GITHUB_OUTPUT.

$opts = ['base' => null, 'allow' => [], 'deny' => [], 'deny-added' => [], 'flag-added' => []];
for ($i = 1; $i < $argc; $i++) {
    $key = ltrim($argv[$i], '-');
    if (! array_key_exists($key, $opts) || ! isset($argv[$i + 1])) {
        fwrite(STDERR, "unknown or incomplete option: {$argv[$i]}\n");
        exit(2);
    }
    $value = $argv[++$i];
    is_array($opts[$key]) ? $opts[$key][] = $value : $opts[$key] = $value;
}
if ($opts['base'] === null) {
    fwrite(STDERR, "--base is required\n");
    exit(2);
}

$base = escapeshellarg($opts['base']);
$files = array_filter(explode("\n", (string) shell_exec("git diff --name-only $base HEAD")));
$matches = fn (string $file, array $globs) => array_filter($globs, fn ($g) => fnmatch($g, $file)) !== [];

$violations = [];
$flags = [];
foreach ($files as $file) {
    if ($opts['allow'] && ! $matches($file, $opts['allow'])) {
        $violations[] = "$file: outside the allowed paths (".implode(', ', $opts['allow']).')';
    }
    if ($matches($file, $opts['deny'])) {
        $violations[] = "$file: protected path, must not change";
    }
}

$file = null;
foreach (explode("\n", (string) shell_exec("git diff -U0 --no-color $base HEAD")) as $line) {
    if (str_starts_with($line, '+++ ')) {
        $file = preg_replace('#^\+\+\+ (b/)?#', '', $line);
        continue;
    }
    if (! str_starts_with($line, '+') || str_starts_with($line, '+++')) {
        continue;
    }
    $added = substr($line, 1);
    foreach ($opts['deny-added'] as $re) {
        if (preg_match("#$re#", $added)) {
            $violations[] = "$file: added line matches forbidden /$re/: ".trim($added);
        }
    }
    foreach ($opts['flag-added'] as $re) {
        if (preg_match("#$re#", $added)) {
            $flags[] = "$file: /$re/: ".trim($added);
        }
    }
}

foreach ($flags as $flag) {
    echo "FLAG: $flag\n";
}
foreach ($violations as $violation) {
    fwrite(STDERR, "VIOLATION: $violation\n");
}
if ($out = getenv('GITHUB_OUTPUT')) {
    file_put_contents($out, 'flagged='.($flags ? 'true' : 'false')."\n", FILE_APPEND);
}
exit($violations ? 1 : 0);
