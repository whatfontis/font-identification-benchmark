<?php
/*
run_benchmark.php — one call per image, writes CSV + JSON + metadata.

Adapted from Font-Finder's test_images.php with:
  - Per-run folder: results/YYYY-MM-DD_<run-id>/
  - run_metadata.json (endpoint, date, mode, git refs)
  - summary.json (Top-1/5/10/20 + avg ms + errors + not-found)
  - results.csv (same schema as Font-Finder: file,expected,rank,ms,top1,top5,top10,top20)

Usage:
  1. put your API key in $API_KEY below
  2. cd into repo root
  3. php scripts/run_benchmark.php imagenospace   (or awithspace / image)

Endpoint targeted: https://www.whatfontis.com/api2/ (public API).
See README.md § "Method" for what "correct" means.
*/

// API key — 3 options (checked in order):
//   1. env var WFI_API_KEY     (recommended for shared machines / CI)
//   2. file scripts/api_key.php that returns the key (gitignored)
//   3. edit the fallback string below (do NOT commit)
$API_KEY = getenv('WFI_API_KEY') ?: null;
if (!$API_KEY && file_exists(__DIR__ . '/api_key.php')) {
    $API_KEY = require __DIR__ . '/api_key.php';
}
if (!$API_KEY) $API_KEY = 'XXXXXXXX';   // ← last-resort fallback (edit here if not using env/file)

// Endpoint & optional AIMODEL parameter — overridable via env:
//   WFI_ENDPOINT=<url>  WFI_AIMODEL=<model>  php scripts/run_benchmark.php imagenospace
$ENDPOINT = getenv('WFI_ENDPOINT') ?: 'https://www.whatfontis.com/api2/';
$AIMODEL  = getenv('WFI_AIMODEL')  ?: null;
// The API's internal "model" diagnostics block is dropped from responses.jsonl unless WFI_RECORD_MODEL=1.
$RECORD_MODEL = (getenv('WFI_RECORD_MODEL') === '1');
$LIMIT    = 20;
$DEPTHS   = [1, 5, 10, 20];
$SLEEP    = 0;
$MODE     = 'fully_automated';  // no cropping, no per-image character hints
$TIMEOUT  = 120;

// Optional smoke-test cap — WFI_MAX_IMAGES=3 runs on the first 3 images only.
// Useful to verify wiring before spending 30 minutes on the full 624 set.
$MAX_IMAGES = (int)(getenv('WFI_MAX_IMAGES') ?: 0);

// ── input folder ────────────────────────────────────────────────────────────
$folderArg = $argv[1] ?? 'imagenospace';
$folder    = rtrim($folderArg, '/\\');
$repoRoot  = dirname(__DIR__);
$candidates = [$folder, "$repoRoot/test-images/$folder"];
$folderReal = null;
foreach ($candidates as $c) if (is_dir($c)) { $folderReal = $c; break; }
if (!$folderReal) {
    fwrite(STDERR, "Not a folder: $folder\nTried: ".implode(' ; ', $candidates)."\nUnzip test-images/anospace.zip first.\n");
    exit(1);
}
if ($API_KEY === 'XXXXXXXX') {
    fwrite(STDERR, "Set your API key: WFI_API_KEY=... or edit \$API_KEY in this file.\nGet one: https://www.whatfontis.com/API-identify-fonts-from-image.html\n");
    exit(1);
}

// ── ground truth ────────────────────────────────────────────────────────────
$infoPath = null;
foreach ([$folderReal.'/info.txt', $repoRoot.'/test-images/info.txt', 'info.txt'] as $p)
    if (file_exists($p)) { $infoPath = $p; break; }
if (!$infoPath) { fwrite(STDERR, "info.txt not found\n"); exit(1); }
$truth = [];
foreach (file($infoPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos($line, '|') === false) continue;
    list($file, $font) = explode('|', $line, 2);
    $truth[trim($file)] = trim($font);
}
if (!$truth) { fwrite(STDERR, "info.txt is empty\n"); exit(1); }

require_once __DIR__ . '/match_helpers.php';

// ── output folder ───────────────────────────────────────────────────────────
$startDate = gmdate('Y-m-d');
$runId     = substr(md5(uniqid('', true)), 0, 8);
$outDir    = "$repoRoot/results/{$startDate}_{$runId}";
if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "Cannot create $outDir\n"); exit(1);
}

// ── git refs (for reproducibility) ─────────────────────────────────────────
function gitRef($dir) {
    $head = @file_get_contents("$dir/.git/HEAD");
    if (!$head) return null;
    if (preg_match('#^ref: (.+)$#m', $head, $m)) {
        return trim(@file_get_contents("$dir/.git/".$m[1]) ?: '') ?: null;
    }
    return trim($head) ?: null;
}
$repoGitRef = gitRef($repoRoot);

// ── API call ────────────────────────────────────────────────────────────────
// Returns parsed candidates AND the raw response body so the caller can persist
// it verbatim as proof of what the server actually returned.
function identify($path, $API_KEY, $LIMIT, $ENDPOINT, $TIMEOUT, $AIMODEL = null) {
    // Minimal params — server defaults for the rest (proven to be the right choice:
    // adding NOTTEXTBOXSDETECTION=1 broke identification on img_589 Univers).
    $post = [
        'API_KEY'        => $API_KEY,
        'IMAGEBASE64'    => '1',
        'urlimagebase64' => base64_encode(file_get_contents($path)),
        'limit'          => (string)$LIMIT,
    ];
    if ($AIMODEL) $post['AIMODEL'] = $AIMODEL;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $ENDPOINT,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $TIMEOUT,
        CURLOPT_POSTFIELDS     => http_build_query($post),
    ]);
    $t0 = microtime(true);
    $body = curl_exec($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $rawBody = (string)$body;
    if ($err !== '') return ['error' => $err, 'ms' => $ms, 'http' => $httpCode, 'raw' => $rawBody];
    $j = json_decode($rawBody, true);
    if (!is_array($j)) return ['error' => 'bad JSON: '.substr($rawBody, 0, 120), 'ms' => $ms, 'http' => $httpCode, 'raw' => $rawBody];
    $list  = isset($j['results']) && is_array($j['results']) ? $j['results'] : $j;
    $model = isset($j['model']) && is_array($j['model']) ? $j['model'] : null;
    if (!is_array($list) || isset($list['error'])) {
        return ['error' => 'unexpected shape', 'ms' => $ms, 'http' => $httpCode, 'raw' => $rawBody];
    }
    $out = [];
    foreach ($list as $row) {
        if (is_array($row) && isset($row['title'])) {
            $out[] = ['title' => (string)$row['title'], 'url' => (string)($row['url'] ?? '')];
        }
    }
    return ['results' => $out, 'ms' => $ms, 'http' => $httpCode, 'model' => $model, 'raw' => $rawBody];
}

// Write one line per API call to responses.jsonl (proof-of-run).
// Each line: {file, expected, rank, http, ms, raw:{...server JSON...}, error?}
function persistResponse($fh, $base, $want, $rank, $r) {
    global $RECORD_MODEL;
    $rec = [
        'file'     => $base,
        'expected' => $want,
        'rank'     => $rank,       // 1..N, 0 if not found, null if ERROR
        'http'     => $r['http'] ?? null,
        'ms'       => $r['ms']   ?? null,
    ];
    if (isset($r['error'])) $rec['error'] = $r['error'];
    $rawJson = json_decode(($r['raw'] ?? ''), true);
    if (is_array($rawJson) && !$RECORD_MODEL) unset($rawJson['model']);
    $rec['raw'] = ($rawJson !== null) ? $rawJson : (string)($r['raw'] ?? '');
    fwrite($fh, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

// ── run ─────────────────────────────────────────────────────────────────────
$files = glob("$folderReal/*.jpg") ?: [];
sort($files, SORT_NATURAL);
if (!$files) { fwrite(STDERR, "No .jpg in $folderReal\n"); exit(1); }
if ($MAX_IMAGES > 0 && $MAX_IMAGES < count($files)) {
    $files = array_slice($files, 0, $MAX_IMAGES);
    fwrite(STDERR, "WFI_MAX_IMAGES=$MAX_IMAGES → running on first $MAX_IMAGES images only\n");
}

$hits = array_fill_keys($DEPTHS, 0);
$tested = 0; $errors = 0; $notfound = 0; $totalMs = 0;
$firstBackendModel = null;

$csv = fopen("$outDir/results.csv", 'w');
fputcsv($csv, array_merge(['file', 'expected', 'rank', 'ms'], array_map(function ($d) { return "top$d"; }, $DEPTHS)));

// One-line-per-image raw response log (proof of what the server returned).
$respFh = fopen("$outDir/responses.jsonl", 'w');

// pre-flight metadata (updated at end with summary)
$startedIso = gmdate('c');
$partialMeta = [
    'run_id'          => $runId,
    'date_utc'        => $startedIso,
    'endpoint'        => $ENDPOINT,
    'api_version'     => trim((string)parse_url($ENDPOINT, PHP_URL_PATH), '/'),
    'aimodel'         => $AIMODEL,
    'mode'            => $MODE,
    'folder'          => basename($folderReal),
    'test_set_source' => 'https://github.com/whatfontis/Font-Finder',
    'matcher'         => 'scripts/match_helpers.php',
    'repo_git_ref'    => $repoGitRef,
    'limit'           => $LIMIT,
    'depths'          => $DEPTHS,
    'timeout_s'       => $TIMEOUT,
    'total_images'    => count($files),
];
file_put_contents("$outDir/run_metadata.json", json_encode($partialMeta, JSON_PRETTY_PRINT));

foreach ($files as $path) {
    $base = basename($path);
    if (!isset($truth[$base])) continue;
    $want = $truth[$base];
    $tested++;

    $r = identify($path, $API_KEY, $LIMIT, $ENDPOINT, $TIMEOUT, $AIMODEL);
    $totalMs += $r['ms'];

    if (isset($r['error'])) {
        $errors++;
        fputcsv($csv, [$base, $want, 'ERROR: '.$r['error'], $r['ms'], '', '', '', '']);
        persistResponse($respFh, $base, $want, null, $r);
        printf("%4d/%d  %-42s ERROR %s\n", $tested, count($files), $base, $r['error']);
        continue;
    }
    if ($firstBackendModel === null && !empty($r['model']['fonts_used'])) {
        $firstBackendModel = $r['model']['fonts_used'];
    }

    $found = findPosition($want, $r['results']);
    $rank = ($found['pos'] > 0) ? $found['pos'] : 0;
    if ($rank === 0) $notfound++;
    $row = [$base, $want, $rank ?: 'not found', $r['ms']];
    foreach ($DEPTHS as $d) {
        $ok = ($rank > 0 && $rank <= $d);
        if ($ok) $hits[$d]++;
        $row[] = $ok ? 1 : 0;
    }
    fputcsv($csv, $row);
    persistResponse($respFh, $base, $want, $rank, $r);

    if ($tested % 25 === 0 || $rank === 0) {
        $eff = max(1, $tested - $errors);
        printf("%4d/%d  %-42s %-10s  running Top1 %.1f%%\n",
            $tested, count($files), $base, $rank ?: 'not found', $hits[1] * 100 / $eff);
    }
    if ($SLEEP > 0) sleep($SLEEP);
}
fclose($csv);
fclose($respFh);

// ── final metadata + summary ────────────────────────────────────────────────
$finishedIso = gmdate('c');
$eff = max(1, $tested - $errors);

if ($RECORD_MODEL) $partialMeta['backend_model_first_seen'] = $firstBackendModel;
$partialMeta['finished_utc']             = $finishedIso;
file_put_contents("$outDir/run_metadata.json", json_encode($partialMeta, JSON_PRETTY_PRINT));

$summary = [
    'run_id'    => $runId,
    'tested'    => $tested,
    'errors'    => $errors,
    'scored_on' => $eff,
    'not_found' => $notfound,
    'avg_ms'    => $tested ? (int)round($totalMs / $tested) : 0,
    'top'       => [],
];
foreach ($DEPTHS as $d) {
    $summary['top']["top{$d}"] = [
        'hits'    => $hits[$d],
        'of'      => $eff,
        'pct'     => $eff ? round($hits[$d] * 100 / $eff, 2) : 0,
    ];
}
file_put_contents("$outDir/summary.json", json_encode($summary, JSON_PRETTY_PRINT));

// ── console output ──────────────────────────────────────────────────────────
echo "\n";
echo "run_id       : $runId\n";
echo "folder       : ".basename($folderReal)."\n";
echo "tested       : $tested   errors: $errors   scored on: $eff\n";
echo "not found    : $notfound\n";
printf("avg time     : %dms\n", $tested ? (int)round($totalMs / $tested) : 0);
echo "backend_model: ".($firstBackendModel ?? '(not surfaced)')."\n\n";
foreach ($DEPTHS as $d) {
    printf("  Top-%-3d %6.1f%%   (%d/%d)\n",
        $d, $eff ? $hits[$d] * 100 / $eff : 0, $hits[$d], $eff);
}
echo "\nwritten to:\n";
echo "  $outDir/results.csv\n";
echo "  $outDir/responses.jsonl   (raw API JSON, one line per image — proof of run)\n";
echo "  $outDir/run_metadata.json\n";
echo "  $outDir/summary.json\n";
