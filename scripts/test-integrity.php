<?php
// Usage: test-integrity.php <base> [<head>=HEAD] [--strict]
// Test-integrity gate: flags diffs that could weaken the test suite.
//   - a test file lost test cases (or was deleted)
//   - a test file lost assertions
//   - new skip/todo/incomplete/only markers
//   - existing assertion lines were rewritten (changed expected values)
// Prints a Markdown report (empty when clean). Exit 0 by default: on human PRs
// this is a label + comment, never a failure. --strict exits 1 on findings;
// loops use that for commits made by Claude.

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => $a !== '--strict'));
$strict = in_array('--strict', $argv, true);
if (! isset($args[0])) {
    fwrite(STDERR, "usage: test-integrity.php <base> [head] [--strict]\n");
    exit(2);
}
$base = $args[0];
$head = $args[1] ?? 'HEAD';

const TEST_FILE = '#^(tests|e2e)/.+\.(php|ts|js)$#';
const TEST_CASE = '/(?:^|[^\w>.$])(?:test|it)\s*\(\s*[\'"]|function\s+test\w*\s*\(|#\[Test\]|@test\b/m';
const ASSERTION = '/\bexpect\s*\(|\bassert[A-Z]\w*\s*\(|->to[A-Z]\w*\s*\(/';
const SKIP = '/->skip\s*\(|markTestSkipped|markTestIncomplete|->todo\s*\(|->only\s*\(|\btest\.(?:skip|only|fixme)\s*\(|#\[(?:Skip|Group\([\'"]skip)/';

function git(string $cmd): string
{
    return (string) shell_exec("git $cmd 2>/dev/null");
}

function show(string $ref, string $path): ?string
{
    exec('git cat-file -e '.escapeshellarg("$ref:$path").' 2>/dev/null', $_, $code);

    return $code === 0 ? git('show '.escapeshellarg("$ref:$path")) : null;
}

$range = escapeshellarg($base).' '.escapeshellarg($head);
$files = array_filter(
    explode("\n", git("diff --name-only $range")),
    fn ($f) => preg_match(TEST_FILE, $f),
);

$findings = [];
foreach ($files as $file) {
    $old = show($base, $file);
    $new = show($head, $file);
    if ($old === null) {
        continue; // new test file: only adds coverage
    }
    if ($new === null) {
        $findings[] = "`$file` was **deleted** (".preg_match_all(TEST_CASE, $old).' test cases).';
        continue;
    }

    [$oldTests, $newTests] = [preg_match_all(TEST_CASE, $old), preg_match_all(TEST_CASE, $new)];
    if ($newTests < $oldTests) {
        $findings[] = "`$file`: test cases $oldTests -> $newTests.";
    }
    [$oldAsserts, $newAsserts] = [preg_match_all(ASSERTION, $old), preg_match_all(ASSERTION, $new)];
    if ($newAsserts < $oldAsserts) {
        $findings[] = "`$file`: assertions $oldAsserts -> $newAsserts.";
    }

    // Walk the zero-context diff hunk by hunk.
    $hunks = preg_split('/^@@.*$/m', git('diff -U0 --no-color '.$range.' -- '.escapeshellarg($file)));
    foreach (array_slice($hunks, 1) as $hunk) {
        $removed = $added = [];
        foreach (explode("\n", $hunk) as $line) {
            if (str_starts_with($line, '-')) {
                $removed[] = substr($line, 1);
            } elseif (str_starts_with($line, '+')) {
                $added[] = substr($line, 1);
            }
        }
        foreach ($added as $line) {
            if (preg_match(SKIP, $line)) {
                $findings[] = "`$file`: new skip marker: `".trim($line).'`';
            }
        }
        $removedAsserts = array_filter($removed, fn ($l) => preg_match(ASSERTION, $l));
        if ($removedAsserts && $added) {
            $findings[] = "`$file`: assertion rewritten:\n  - `".trim(reset($removedAsserts))."`\n  + `".trim($added[0]).'`';
        }
    }
}

if ($findings) {
    echo "<!-- test-integrity -->\n### Test-integrity gate\n\n";
    echo "This change may weaken the test suite. Review these before merging:\n\n";
    foreach ($findings as $finding) {
        echo "- $finding\n";
    }
}
exit($strict && $findings ? 1 : 0);
