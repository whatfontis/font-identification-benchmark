<?php
/*
run_llm_benchmark.php — the same 624 images and the same scoring as run_benchmark.php, sent to a
general-purpose multimodal model instead of the WhatFontIs API.

Usage:
  php scripts/run_llm_benchmark.php <provider> <model> [imagenospace]
    provider: openai | anthropic | google

  php scripts/run_llm_benchmark.php openai    gpt-6-astra
  php scripts/run_llm_benchmark.php anthropic claude-fable-5-1
  php scripts/run_llm_benchmark.php google    gemini-3.8-flash

Keys: OPENAI_API_KEY / ANTHROPIC_API_KEY / GEMINI_API_KEY, or scripts/llm_keys.php (see llm_keys.php.example).
Optional env: WFI_MAX_IMAGES=3 (smoke test), LLM_MAX_TOKENS (default 16000), LLM_TIMEOUT_S (default 600),
LLM_CONCURRENCY (parallel requests, default 4).

Only the image bytes and the prompt in prompts/font_id_llm.txt are sent; the file name is not.
The reply is parsed into a ranked list of font names and scored with scripts/match_helpers.php.
A reply without a parsable list counts as a miss (not an error); only a failed HTTP call is an error.
*/

require_once __DIR__ . '/match_helpers.php';
require_once __DIR__ . '/llm_helpers.php';

$provider = $argv[1] ?? '';
$model    = $argv[2] ?? '';
$folder   = $argv[3] ?? 'imagenospace';
if (!in_array($provider, llm_providers(), true) || $model === '') {
    fwrite(STDERR, "usage: php scripts/run_llm_benchmark.php <openai|anthropic|google> <model> [imagenospace]\n");
    exit(2);
}
$apiKey = llm_api_key($provider);
if (!$apiKey) { fwrite(STDERR, "No API key for $provider (env var or scripts/llm_keys.php)\n"); exit(1); }

$LIMIT      = 20;
$DEPTHS     = [1, 5, 10, 20];
$MAX_TOKENS = (int)(getenv('LLM_MAX_TOKENS') ?: 16000);
$TIMEOUT    = (int)(getenv('LLM_TIMEOUT_S') ?: 600);
$MAX_IMAGES = (int)(getenv('WFI_MAX_IMAGES') ?: 0);
$RETRY_WAIT = [10, 30, 90];   // seconds before attempts 2, 3, 4 on 429 / 5xx / network errors
$CONCURRENCY = max(1, (int)(getenv('LLM_CONCURRENCY') ?: 4));

$repoRoot   = dirname(__DIR__);
$promptFile = "$repoRoot/prompts/font_id_llm.txt";
$prompt     = trim(file_get_contents($promptFile));
$folderReal = is_dir($folder) ? $folder : "$repoRoot/test-images/$folder";
if (!is_dir($folderReal)) { fwrite(STDERR, "Not a folder: $folderReal (unzip test-images/anospace.zip first)\n"); exit(1); }

$truth = [];
foreach (file("$repoRoot/test-images/info.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos($line, '|') === false) continue;
    list($f, $font) = explode('|', $line, 2);
    $truth[trim($f)] = trim($font);
}

$files = glob("$folderReal/*.jpg") ?: [];
sort($files, SORT_NATURAL);
if ($MAX_IMAGES > 0) $files = array_slice($files, 0, $MAX_IMAGES);

$runId  = substr(md5(uniqid('', true)), 0, 8);
$slug   = preg_replace('/[^A-Za-z0-9.\-]+/', '-', $model);
$outDir = "$repoRoot/results/" . gmdate('Y-m-d') . "_{$slug}_{$runId}";
mkdir($outDir, 0777, true);

$head = @file_get_contents("$repoRoot/.git/HEAD");
$gitRef = null;
if ($head && preg_match('#^ref: (.+)$#m', $head, $m)) $gitRef = trim((string)@file_get_contents("$repoRoot/.git/" . $m[1])) ?: null;

$meta = [
    'run_id'         => $runId,
    'date_utc'       => gmdate('c'),
    'provider'       => $provider,
    'model'          => $model,
    'mode'           => 'fully_automated',
    'folder'         => basename($folderReal),
    'prompt_file'    => 'prompts/font_id_llm.txt',
    'prompt_sha256'  => hash('sha256', $prompt),
    'request'        => 'image + prompt, provider defaults otherwise (no temperature, no reasoning/thinking settings, no tools, no web search)',
    'max_tokens'     => $MAX_TOKENS,
    'concurrency'    => $CONCURRENCY,
    'timeout_s'      => $TIMEOUT,
    'limit'          => $LIMIT,
    'depths'         => $DEPTHS,
    'matcher'        => 'scripts/match_helpers.php',
    'repo_git_ref'   => $gitRef,
    'total_images'   => count($files),
];
file_put_contents("$outDir/run_metadata.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$csv = fopen("$outDir/results.csv", 'w');
fputcsv($csv, ['file', 'expected', 'rank', 'ms', 'top1', 'top5', 'top10', 'top20', 'answer_1']);
$jsonl = fopen("$outDir/responses.jsonl", 'w');

$hits = array_fill_keys($DEPTHS, 0);
$tested = 0; $errors = 0; $notFound = 0; $unparsed = 0; $refusals = 0; $totalMs = 0;
$modelsSeen = []; $stops = [];

$items = [];
foreach ($files as $path) {
    if (isset($truth[basename($path)])) $items[] = $path;
}

// $CONCURRENCY requests at a time; within a batch, only the calls that failed with 429 / 5xx /
// a network error are sent again, after 10 s, 30 s, 90 s. Results are written in file order.
foreach (array_chunk($items, $CONCURRENCY) as $batch) {
    $results = []; $attempts = [];
    $pending = array_keys($batch);
    for ($round = 0; $pending && $round <= count($RETRY_WAIT); $round++) {
        if ($round > 0) sleep($RETRY_WAIT[$round - 1]);
        $handles = [];
        foreach ($pending as $k) {
            $handles[$k] = llm_curl_handle($provider, $model, $apiKey, file_get_contents($batch[$k]), $prompt, $MAX_TOKENS, $TIMEOUT);
        }
        $next = [];
        foreach (llm_run_parallel($handles) as $k => $r) {
            $results[$k] = $r;
            $attempts[$k] = $round + 1;
            if ($r['curl_error'] !== '' || $r['http'] === 429 || $r['http'] >= 500) $next[] = $k;
        }
        $pending = $next;
    }

  foreach ($batch as $k => $path) {
    $base = basename($path);
    $want = $truth[$base];
    $tested++;
    $r = $results[$k];
    $attempt = $attempts[$k];
    $totalMs += $r['ms'];

    $raw = json_decode($r['body'], true);
    $rec = ['file' => $base, 'expected' => $want, 'provider' => $provider, 'http' => $r['http'], 'ms' => $r['ms'], 'attempts' => $attempt];

    if ($r['http'] !== 200 || !is_array($raw)) {
        $errors++;
        $rec['rank'] = null;
        $rec['error'] = $r['curl_error'] !== '' ? $r['curl_error'] : 'HTTP ' . $r['http'];
        $rec['raw'] = is_array($raw) ? $raw : $r['body'];
        fwrite($jsonl, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        fputcsv($csv, [$base, $want, 'ERROR: ' . $rec['error'], $r['ms'], '', '', '', '', '']);
        printf("%4d/%d  %-12s ERROR %s\n", $tested, count($files), $base, $rec['error']);
        continue;
    }

    $x = llm_extract($provider, $raw);
    if ($x['model']) $modelsSeen[$x['model']] = true;
    $stops[(string)$x['stop']] = ($stops[(string)$x['stop']] ?? 0) + 1;
    if ($x['stop'] === 'refusal') $refusals++;

    $names = llm_parse_fonts($x['text'], $LIMIT);
    if ($names === null) { $unparsed++; $names = []; }
    $cands = [];
    foreach ($names as $n) $cands[] = ['title' => $n, 'url' => ''];
    $pos = $cands ? findPosition($want, $cands)['pos'] : -1;
    $rank = $pos > 0 ? $pos : 0;
    if ($rank === 0) $notFound++;

    $rec['rank'] = $rank;
    $rec['fonts'] = $names;
    $rec['raw'] = $raw;
    fwrite($jsonl, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

    $row = [$base, $want, $rank ?: 'not found', $r['ms']];
    foreach ($DEPTHS as $d) {
        $ok = ($rank > 0 && $rank <= $d);
        if ($ok) $hits[$d]++;
        $row[] = $ok ? 1 : 0;
    }
    $row[] = $names[0] ?? '';
    fputcsv($csv, $row);

    $eff = max(1, $tested - $errors);
    printf("%4d/%d  %-12s %-10s %-30s Top1 %.1f%%\n", $tested, count($files), $base,
        $rank ?: 'not found', substr($names[0] ?? '(no answer)', 0, 30), $hits[1] * 100 / $eff);
  }
}
fclose($csv);
fclose($jsonl);

$eff = $tested - $errors;
$meta['finished_utc'] = gmdate('c');
$meta['models_reported'] = array_keys($modelsSeen);
file_put_contents("$outDir/run_metadata.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$summary = [
    'run_id'      => $runId,
    'provider'    => $provider,
    'model'       => $model,
    'tested'      => $tested,
    'errors'      => $errors,
    'scored_on'   => $eff,
    'not_found'   => $notFound,
    'unparsed'    => $unparsed,
    'refusals'    => $refusals,
    'stop_reasons'=> $stops,
    'avg_ms'      => $tested ? (int)round($totalMs / $tested) : 0,
    'top'         => [],
];
foreach ($DEPTHS as $d) {
    $summary['top']["top$d"] = ['hits' => $hits[$d], 'of' => $eff, 'pct' => ($eff ? round($hits[$d] * 100 / $eff, 2) : 0)];
}
file_put_contents("$outDir/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n$provider / $model — run $runId\n";
echo "tested $tested, errors $errors, not found $notFound (unparsed $unparsed, refusals $refusals)\n";
foreach ($DEPTHS as $d) printf("  Top-%-3d %6.2f%%  (%d/%d)\n", $d, $eff ? $hits[$d] * 100 / $eff : 0, $hits[$d], $eff);
echo "written to $outDir\n";
