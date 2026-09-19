<?php
/*
rename_to_opaque_ids.php

Renames the test images to opaque IDs (img_001.jpg .. img_624.jpg) so that
the filename we send to the API cannot look like it hints at the answer.
Writes a fresh info.txt with the new mapping.

Runs against test-images/imagenospace/ and test-images/image/, plus rewrites
the two zips in test-images/. Idempotent: re-running rebuilds from the
original whatfontis/Font-Finder info.txt if it's still there, otherwise
follows the mapping already in test-images/info.txt.

Usage:
  php scripts/rename_to_opaque_ids.php
*/

$repoRoot = dirname(__DIR__);
$tiDir    = "$repoRoot/test-images";
$infoOld  = "$tiDir/info.txt";

if (!file_exists($infoOld)) {
    fwrite(STDERR, "info.txt not found at $infoOld\n"); exit(1);
}

// ── read existing mapping ──────────────────────────────────────────────────
$rows = [];  // list of [oldFilename, fontName]
foreach (file($infoOld, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos($line, '|') === false) continue;
    list($file, $font) = explode('|', $line, 2);
    $rows[] = [trim($file), trim($font)];
}
if (!$rows) { fwrite(STDERR, "info.txt is empty\n"); exit(1); }

// If files are already renamed (img_NNN.jpg) we skip
$alreadyOpaque = true;
foreach ($rows as $r) {
    if (!preg_match('/^img_\d{3}\.jpg$/', $r[0])) { $alreadyOpaque = false; break; }
}
if ($alreadyOpaque) {
    fwrite(STDERR, "info.txt already uses opaque ids — nothing to do.\n");
    exit(0);
}

// ── sort by font name (deterministic, natural) so id -> name is stable ────
usort($rows, function ($a, $b) { return strnatcasecmp($a[1], $b[1]); });

// ── build new mapping ─────────────────────────────────────────────────────
$mapping = [];  // oldFilename => newFilename
$newInfo = [];  // "newFilename|fontName" lines
foreach ($rows as $i => list($oldFile, $font)) {
    $newFile = sprintf('img_%03d.jpg', $i + 1);
    $mapping[$oldFile] = $newFile;
    $newInfo[] = "$newFile|$font";
}
$total = count($rows);
printf("prepared %d mappings: img_001..img_%03d\n", $total, $total);

// ── rename JPGs in each folder that exists ────────────────────────────────
$folders = ['imagenospace', 'image'];
foreach ($folders as $sub) {
    $dir = "$tiDir/$sub";
    if (!is_dir($dir)) { echo "skip $sub/ (not extracted)\n"; continue; }
    $renamed = 0; $missing = 0;
    foreach ($mapping as $old => $new) {
        $src = "$dir/$old"; $dst = "$dir/$new";
        if (!file_exists($src)) { $missing++; continue; }
        if (file_exists($dst)) { unlink($dst); }
        if (rename($src, $dst)) $renamed++;
        else fwrite(STDERR, "  rename failed: $sub/$old -> $new\n");
    }
    echo "  $sub/: renamed $renamed, missing $missing\n";
}

// ── write new info.txt ────────────────────────────────────────────────────
file_put_contents($infoOld, implode("\n", $newInfo) . "\n");
echo "wrote new mapping to test-images/info.txt (" . count($newInfo) . " lines)\n";

// ── rebuild zips ──────────────────────────────────────────────────────────
$zips = [
    'anospace.zip'    => 'imagenospace',
    'awithspace.zip'  => 'image',
];
foreach ($zips as $zipName => $sub) {
    $zipPath = "$tiDir/$zipName";
    $dir     = "$tiDir/$sub";
    if (!is_dir($dir)) { echo "skip zip $zipName (folder $sub/ missing)\n"; continue; }
    if (file_exists($zipPath)) @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "cannot open $zipPath for write\n"); continue;
    }
    // include only img_*.jpg (opaque names)
    $jpgs = glob("$dir/img_*.jpg") ?: [];
    sort($jpgs, SORT_NATURAL);
    foreach ($jpgs as $p) $zip->addFile($p, basename($p));
    // include the new info.txt inside the zip too (matches Font-Finder convention)
    $zip->addFile($infoOld, 'info.txt');
    $count = $zip->numFiles;
    $zip->close();
    echo "  rebuilt $zipName ($count entries, " . number_format(filesize($zipPath)) . " bytes)\n";
}

echo "\ndone.\n";
