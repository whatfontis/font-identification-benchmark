<?php
/*
build_not_found.php — list the images whose font the API did not return, for one run.

  php scripts/build_not_found.php results/<run-folder> [results/<earlier-run> ...]

Writes <run>/not_found.txt: `file|expected|what the API returned first`, one line per miss.
Each earlier run given is compared against: for every one, a `not_found_vs_<run-id>.txt` lists
the misses that are new versus it — the ones worth looking at after a change.

Local only, not committed: with --images it also copies the JPGs into <run>/not_found_images/
(and a subfolder per comparison), so the misses can be reviewed in a file manager.
*/

$args = array_slice($argv, 1);
$withImages = in_array('--images', $args, true);
$args = array_values(array_filter($args, function ($a) { return $a !== '--images'; }));
if (!$args) { fwrite(STDERR, "usage: php scripts/build_not_found.php results/<run> [results/<earlier-run> ...] [--images]\n"); exit(2); }

$repoRoot = dirname(__DIR__);
function runDir($repoRoot, $p) {
    $d = rtrim($p, '/\\');
    if (!is_dir($d)) $d = "$repoRoot/$d";
    if (!is_file("$d/results.csv")) { fwrite(STDERR, "no results.csv in $d\n"); exit(2); }
    return $d;
}
// [file => [expected, what the API returned first]] for the rows scored "not found".
// results.csv has the rank; the first title comes from the saved raw response.
function misses($dir) {
    $top1 = [];
    if (is_file("$dir/responses.jsonl")) {
        $fh = fopen("$dir/responses.jsonl", 'r');
        while (($line = fgets($fh)) !== false) {
            $o = json_decode($line, true);
            if (!is_array($o) || !isset($o['file'])) continue;
            // Runs of general-purpose models carry the parsed answer in `fonts`;
            // WhatFontIs runs carry the raw candidate list (optionally wrapped in `results`).
            if (isset($o['fonts']) && is_array($o['fonts'])) {
                $top1[$o['file']] = isset($o['fonts'][0]) ? (string)$o['fonts'][0] : '';
                continue;
            }
            $raw = isset($o['raw']) ? $o['raw'] : null;
            $list = (is_array($raw) && isset($raw['results'])) ? $raw['results'] : $raw;
            $top1[$o['file']] = (is_array($list) && isset($list[0]['title'])) ? $list[0]['title'] : '';
        }
        fclose($fh);
    }
    $h = fopen("$dir/results.csv", 'r');
    fgetcsv($h);
    $out = [];
    while (($r = fgetcsv($h)) !== false) {
        if (count($r) < 4 || $r[2] !== 'not found') continue;
        $out[$r[0]] = [$r[1], isset($top1[$r[0]]) ? $top1[$r[0]] : ''];
    }
    fclose($h);
    return $out;
}
function copyImages($repoRoot, $dst, $set) {
    @mkdir($dst, 0777, true);
    $src = "$repoRoot/test-images/imagenospace";
    foreach ($set as $file => $v) {
        $num = (int)preg_replace('/[^0-9]/', '', $file);
        $safe = preg_replace('/[<>:"\/\\\\|?*]/', '', $v[0]);
        @copy("$src/$file", sprintf('%s/img_%03d_%s.jpg', $dst, $num, $safe));
    }
}

$dir = runDir($repoRoot, $args[0]);
$now = misses($dir);
$runId = basename($dir);

$out = "# Images whose font was not in the API's top 20 ($runId)\n# file|expected|what the API returned first\n";
foreach ($now as $file => $v) $out .= "$file|{$v[0]}|{$v[1]}\n";
file_put_contents("$dir/not_found.txt", $out);
echo basename($dir), ": ", count($now), " not found -> not_found.txt\n";
if ($withImages) copyImages($repoRoot, "$dir/not_found_images", $now);

foreach (array_slice($args, 1) as $p) {
    $other = runDir($repoRoot, $p);
    $new = array_diff_key($now, misses($other));
    $tag = basename($other);
    $txt = "# Misses in $runId that were found in $tag\n# file|expected|what the API returned first\n";
    foreach ($new as $file => $v) $txt .= "$file|{$v[0]}|{$v[1]}\n";
    file_put_contents("$dir/not_found_vs_$tag.txt", $txt);
    echo "  vs $tag: ", count($new), " new -> not_found_vs_$tag.txt\n";
    if ($withImages) copyImages($repoRoot, "$dir/not_found_images/_new_vs_$tag", $new);
}
