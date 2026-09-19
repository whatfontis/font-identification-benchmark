<?php
/*
rescore.php — recompute a run's score from its saved raw API responses. No API key, no network.

Usage:
  php scripts/rescore.php results/2026-09-18_d7b6d7de

Reads results/<run>/responses.jsonl, takes the ground truth from test-images/info.txt (not from the
jsonl), runs the same matcher as run_benchmark.php and prints Top-1/5/10/20. Then compares with the
run's summary.json and exits 1 if anything differs.
*/

if ($argc < 2) { fwrite(STDERR, "usage: php scripts/rescore.php results/<run-folder>\n"); exit(2); }
$runDir   = rtrim($argv[1], '/\\');
$repoRoot = dirname(__DIR__);
if (!is_dir($runDir)) $runDir = "$repoRoot/$runDir";
if (!is_file("$runDir/responses.jsonl")) { fwrite(STDERR, "no responses.jsonl in $runDir\n"); exit(2); }

require_once __DIR__ . '/match_helpers.php';
require_once __DIR__ . '/llm_helpers.php';

$truth = [];
foreach (file("$repoRoot/test-images/info.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos($line, '|') === false) continue;
    list($f, $font) = explode('|', $line, 2);
    $truth[trim($f)] = trim($font);
}

$depths = [1, 5, 10, 20];
$hits = array_fill_keys($depths, 0);
$tested = 0; $errors = 0; $notFound = 0; $rankMismatch = 0;

$fh = fopen("$runDir/responses.jsonl", 'r');
while (($line = fgets($fh)) !== false) {
    $rec = json_decode($line, true);
    if (!is_array($rec)) continue;
    $file = $rec['file'];
    if (!isset($truth[$file])) { fwrite(STDERR, "not in info.txt: $file\n"); continue; }
    $tested++;
    $raw = $rec['raw'] ?? null;
    $cands = [];
    if (!empty($rec['provider'])) {
        // General-purpose model: re-parse the font names from the model's raw reply.
        if (!empty($rec['error']) || !is_array($raw)) { $errors++; continue; }
        $x = llm_extract($rec['provider'], $raw);
        foreach (llm_parse_fonts($x['text']) ?: [] as $name) $cands[] = ['title' => $name, 'url' => ''];
    } else {
        $list = (is_array($raw) && isset($raw['results']) && is_array($raw['results'])) ? $raw['results'] : $raw;
        if (!empty($rec['error']) || !is_array($list) || isset($list['error'])) { $errors++; continue; }
        foreach ($list as $row) {
            if (is_array($row) && isset($row['title'])) $cands[] = ['title' => (string)$row['title'], 'url' => (string)($row['url'] ?? '')];
        }
    }
    $pos = findPosition($truth[$file], $cands)['pos'];
    $rank = $pos > 0 ? $pos : 0;
    if ($rank === 0) $notFound++;
    foreach ($depths as $d) if ($rank > 0 && $rank <= $d) $hits[$d]++;
    if (isset($rec['rank']) && (int)$rec['rank'] !== $rank) $rankMismatch++;
}
fclose($fh);

$eff = max(1, $tested - $errors);
echo "run        : ", basename($runDir), "\n";
echo "tested     : $tested   errors: $errors   scored on: $eff   not found: $notFound\n";
foreach ($depths as $d) printf("Top-%-3d %6.2f%%  (%d/%d)\n", $d, $hits[$d] * 100 / $eff, $hits[$d], $eff);
echo "rank differs from the rank stored in responses.jsonl: $rankMismatch\n";

$ok = ($rankMismatch === 0);
if (is_file("$runDir/summary.json")) {
    $s = json_decode(file_get_contents("$runDir/summary.json"), true);
    foreach ($depths as $d) {
        if ((int)$s['top']["top$d"]['hits'] !== $hits[$d]) { $ok = false; echo "summary.json Top-$d hits = {$s['top']["top$d"]['hits']}, recomputed = {$hits[$d]}\n"; }
    }
    if ((int)$s['not_found'] !== $notFound) { $ok = false; echo "summary.json not_found = {$s['not_found']}, recomputed = $notFound\n"; }
}
echo $ok ? "OK: matches summary.json\n" : "MISMATCH\n";
exit($ok ? 0 : 1);
