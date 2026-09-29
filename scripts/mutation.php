<?php
// Mutation loop helpers over `pest --mutate` output (Pest has no JSON mutation
// report, and Infection dropped Pest support, so we parse the text report).
//
//   mutation.php parse <pest-output.txt>          -> JSON {score, escaped: [...]}
//   mutation.php next <parsed.json> [skip-file]   -> "Next: <Class>" (most escaped mutants)
//   mutation.php ratchet <before.json> <after.json>
//
// Escaped mutants are identified by Pest's mutation ID, which is stable while
// the source is unchanged. The loop forbids source changes, so the ratchet is
// exact: the escaped set after must be a strict subset of the set before.

const LINE = '/^\s*([A-Z]+)\s+(\S+\.php)\s+>\s+Line (\d+): (\w+) - ID: ([0-9a-f]+)/';

function parse(string $file): array
{
    $text = preg_replace('/\e\[[0-9;]*m/', '', (string) file_get_contents($file));
    // laravel/pao wraps output lines in JSON when it detects an AI agent.
    $json = json_decode($text, true);
    if (is_array($json)) {
        $strings = [];
        array_walk_recursive($json, function ($v) use (&$strings) {
            $strings[] = (string) $v;
        });
        $text = implode("\n", $strings);
    }
    $lines = explode("\n", $text);

    $escaped = [];
    $score = null;
    foreach ($lines as $line) {
        if (preg_match(LINE, $line, $m)) {
            $escaped[$m[5]] = ['status' => $m[1], 'file' => $m[2], 'line' => (int) $m[3], 'mutator' => $m[4], 'id' => $m[5]];
        } elseif (preg_match('/Score:\s+([\d.]+)%/', $line, $m)) {
            $score = (float) $m[1];
        }
    }

    return ['score' => $score, 'escaped' => array_values($escaped)];
}

function classOf(string $file): string
{
    // PSR-4 App\ => app/
    return 'App\\'.str_replace('/', '\\', preg_replace(['#^app/#', '#\.php$#'], '', $file));
}

function load(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (! is_array($data) || ! isset($data['escaped'])) {
        fwrite(STDERR, "not a parsed mutation report: $file\n");
        exit(2);
    }

    return $data;
}

switch ($argv[1] ?? '') {
    case 'parse':
        $report = parse($argv[2] ?? 'php://stdin');
        if ($report['score'] === null) {
            fwrite(STDERR, "no mutation score found in pest output\n");
            exit(2);
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        break;

    case 'next':
        $skip = isset($argv[3]) && is_file($argv[3])
            ? array_filter(array_map('trim', file($argv[3])), fn ($l) => $l !== '' && $l[0] !== '#')
            : [];
        $byClass = [];
        foreach (load($argv[2])['escaped'] as $m) {
            if (str_starts_with($m['file'], 'app/') && ! in_array(classOf($m['file']), $skip, true)) {
                $byClass[classOf($m['file'])] = ($byClass[classOf($m['file'])] ?? 0) + 1;
            }
        }
        uksort($byClass, fn ($a, $b) => [$byClass[$b], $a] <=> [$byClass[$a], $b]);
        echo 'Total escaped: '.array_sum($byClass)."\n";
        if ($byClass) {
            $class = array_key_first($byClass);
            echo "Next: $class ({$byClass[$class]} escaped)\n";
        }
        break;

    case 'ratchet':
        $before = array_column(load($argv[2])['escaped'], null, 'id');
        $after = array_column(load($argv[3])['escaped'], null, 'id');
        $failed = false;
        foreach (array_diff_key($after, $before) as $m) {
            fwrite(STDERR, "Previously killed mutant escaped again: {$m['file']}:{$m['line']} {$m['mutator']} ({$m['id']})\n");
            $failed = true;
        }
        if (count($after) >= count($before)) {
            fwrite(STDERR, 'No new mutants killed ('.count($before).' -> '.count($after)." escaped).\n");
            $failed = true;
        }
        echo 'Escaped mutants: '.count($before).' -> '.count($after)."\n";
        exit($failed ? 1 : 0);

    default:
        fwrite(STDERR, "usage: mutation.php parse|next|ratchet ...\n");
        exit(2);
}
