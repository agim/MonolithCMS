<?php
/**
 * MonolithCMS — Merge Script
 *
 * Merges tmp/sections/s*.php back into index.php.
 *   1. Creates a timestamped backup of the current index.php
 *   2. Concatenates section files in lexicographic order (sNN prefix)
 *   3. Runs `php -l` syntax check before overwriting
 *   4. Overwrites index.php with the merged content
 *   5. Deletes tmp/sections/ and its files
 *   6. Prunes tmp/backups/ to the 10 most recent backup files
 *
 * Usage:  php scripts/merge.php
 */

$root        = dirname(__DIR__);
$indexFile   = $root . '/index.php';
$sectionsDir = $root . '/tmp/sections';
$backupsDir  = $root . '/tmp/backups';
$tmpMerge    = $root . '/tmp/index_merge_tmp.php';

// ── Validation function ───────────────────────────────────────────────────────

/**
 * Validates that every section header in $content follows the convention:
 *
 *   // ─────────────────────────────────────── (exactly 77 ─ characters)
 *   // SECTION N: NAME IN UPPER CASE
 *   // ─────────────────────────────────────── (exactly 77 ─ characters)
 *
 * Rules enforced:
 *   - Numbers are integers only (no letters like 10B)
 *   - Numbers are sequential 1, 2, 3 … with no gaps
 *   - No duplicate numbers
 *   - Name is UPPER CASE words (no lowercase letters)
 *   - Divider lines are exactly // + space + 77 × U+2500
 *
 * Returns array of error strings (empty = valid).
 */
function validateSectionHeaders(string $content): array {
    $errors  = [];
    $lines   = explode("\n", $content);
    $total   = count($lines);
    $divider = '// ' . str_repeat("\u{2500}", 77);
    $found   = []; // [lineNum => sectionNumber]

    for ($i = 0; $i < $total - 2; $i++) {
        $l1 = rtrim($lines[$i]);
        $l2 = rtrim($lines[$i + 1]);
        $l3 = rtrim($lines[$i + 2]);

        // Only inspect lines that look like section dividers
        if ($l1 !== $divider) {
            continue;
        }

        if (!preg_match('/^\/\/ SECTION (\d+[A-Za-z]*): (.+)$/', $l2, $m)) {
            // It's a divider but line 2 is not a SECTION header — could be
            // a sub-comment divider (like "─── SETUP ROUTES ───"). Skip.
            continue;
        }

        $rawNum  = $m[1];
        $name    = $m[2];
        $lineNum = $i + 2; // 1-based line of the SECTION comment

        // Rule 1: integer only (no letters)
        if (!ctype_digit($rawNum)) {
            $errors[] = "Line {$lineNum}: SECTION number must be an integer — got \"SECTION {$rawNum}\"";
        }

        $num = (int) $rawNum;

        // Rule 2: no duplicates
        if (isset($found[$num])) {
            $errors[] = "Line {$lineNum}: duplicate SECTION {$num} (first seen at line {$found[$num]})";
        } else {
            $found[$num] = $lineNum;
        }

        // Rule 3: name must be UPPER CASE (allow spaces, digits, &, /, -)
        if (preg_match('/[a-z]/', $name)) {
            $errors[] = "Line {$lineNum}: SECTION {$num} name must be UPPER CASE — got \"{$name}\"";
        }

        // Rule 4: closing divider must also be exact
        if ($l3 !== $divider) {
            $errors[] = "Line " . ($i + 3) . ": closing divider for SECTION {$num} is malformed";
        }
    }

    // Rule 5: sequential with no gaps (only check if no duplicates found yet)
    if (!empty($found)) {
        ksort($found);
        $nums     = array_keys($found);
        $expected = range(1, max($nums));
        $missing  = array_diff($expected, $nums);
        foreach ($missing as $gap) {
            $errors[] = "SECTION {$gap} is missing — numbers must be sequential with no gaps";
        }
    }

    return $errors;
}

// ── Preflight ────────────────────────────────────────────────────────────────

if (!is_dir($sectionsDir)) {
    fwrite(STDERR, "\nERROR: tmp/sections/ does not exist.\n");
    fwrite(STDERR, "       Nothing to merge. Run split first: php scripts/split.php\n\n");
    exit(1);
}

$files = glob($sectionsDir . '/s*.php');
if (empty($files)) {
    fwrite(STDERR, "\nERROR: No s*.php files found in tmp/sections/\n\n");
    exit(1);
}

// Lexicographic sort on sNN prefix guarantees correct section order
natsort($files);
$files = array_values($files);

echo "\n✓ Found " . count($files) . " section files.\n";

// ── Backup ───────────────────────────────────────────────────────────────────

if (!is_dir($backupsDir)) {
    mkdir($backupsDir, 0755, true);
}

$timestamp  = date('Ymd_His');
$backupFile = $backupsDir . "/index_{$timestamp}.php";

if (!copy($indexFile, $backupFile)) {
    fwrite(STDERR, "\nERROR: Could not write backup to {$backupFile}\n\n");
    exit(1);
}
echo "✓ Backup saved: tmp/backups/index_{$timestamp}.php\n";

// ── Concatenate ───────────────────────────────────────────────────────────────

$merged = '';
foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "\nERROR: Could not read {$file}\n");
        fwrite(STDERR, "index.php was NOT modified.\n\n");
        exit(1);
    }
    $merged .= $content;
}

// ── Auto-increment version ───────────────────────────────────────────────────

if (preg_match("/define\('MONOLITHCMS_VERSION', '(\d+)\.(\d+)\.(\d+)'\);/", $merged, $match)) {
    $major = (int) $match[1];
    $minor = (int) $match[2];
    $patch = (int) $match[3];

    $oldVersion = "{$major}.{$minor}.{$patch}";
    $newVersion = "{$major}.{$minor}." . ($patch + 1);

    $merged = preg_replace(
        "/define\('MONOLITHCMS_VERSION', '\d+\.\d+\.\d+'\);/",
        "define('MONOLITHCMS_VERSION', '{$newVersion}');",
        $merged,
        1
    );

    // Also keep the file-header comment in sync
    $merged = preg_replace(
        '/ \* Version: \d+\.\d+\.\d+/',
        " * Version: {$newVersion}",
        $merged,
        1
    );

    echo "✓ Version: {$oldVersion} → {$newVersion}\n";
} else {
    fwrite(STDERR, "\nWARNING: Could not find MONOLITHCMS_VERSION in merged content.\n");
}

// ── Validate section convention (before touching any files) ──────────────────

$validationErrors = validateSectionHeaders($merged);
if (!empty($validationErrors)) {
    fwrite(STDERR, "\n✗ Section convention violation(s) detected — index.php was NOT modified.\n\n");
    foreach ($validationErrors as $err) {
        fwrite(STDERR, "  • {$err}\n");
    }
    fwrite(STDERR, "\nFix the section headers in the relevant s*.php file and re-run merge.\n");
    fwrite(STDERR, "Convention: // SECTION N: NAME IN UPPER CASE  (integers only, sequential, no gaps)\n");
    fwrite(STDERR, "Section files are preserved in tmp/sections/ — edit and re-run: php scripts/merge.php\n\n");
    exit(1); // index.php untouched — no chmod needed
}

$sectionCount = preg_match_all('/^\/\/ SECTION \d+:/m', $merged);
echo "✓ Section headers validated: {$sectionCount} sections, sequential, no duplicates.\n";

// Write merged content to a temporary file for the lint check
if (file_put_contents($tmpMerge, $merged, LOCK_EX) === false) {
    fwrite(STDERR, "\nERROR: Could not write temporary merge file: {$tmpMerge}\n\n");
    exit(1);
}

// ── PHP syntax check ──────────────────────────────────────────────────────────

$lintOutput = [];
$lintCode   = 0;
exec('php -l ' . escapeshellarg($tmpMerge) . ' 2>&1', $lintOutput, $lintCode);

if ($lintCode !== 0) {
    @unlink($tmpMerge);
    fwrite(STDERR, "\n✗ PHP syntax error in merged file — index.php was NOT modified.\n\n");
    fwrite(STDERR, implode("\n", $lintOutput) . "\n\n");
    fwrite(STDERR, "Fix the error in the relevant section file and run merge again.\n");
    fwrite(STDERR, "Section files are preserved in tmp/sections/ — edit and re-run: php scripts/merge.php\n");
    fwrite(STDERR, "Backup is at: tmp/backups/index_{$timestamp}.php\n\n");
    exit(1);
}

echo "✓ PHP syntax check passed.\n";

// ── Rebuild index.php ────────────────────────────────────────────────────────

// Delete the existing read-only file, then rename the validated tmp file into
// place. This avoids needing to unlock (chmod 644) the old file and is cleaner
// than an in-place overwrite: backup is safe, tmp is validated, old is gone.
if (!@unlink($indexFile)) {
    @unlink($tmpMerge);
    fwrite(STDERR, "\nERROR: Could not delete index.php before rebuild.\n");
    fwrite(STDERR, "       Check file permissions (should be 0444, owner matches process user).\n\n");
    exit(1);
}

// rename() is atomic on the same filesystem (typical case)
$renamed = @rename($tmpMerge, $indexFile);
if (!$renamed) {
    // Fallback: cross-device move — copy tmp into place, clean up
    if (!copy($tmpMerge, $indexFile)) {
        @unlink($tmpMerge);
        // Restore from backup
        copy($backupFile, $indexFile);
        @chmod($indexFile, 0444);
        fwrite(STDERR, "\nERROR: Could not write index.php. Backup restored.\n\n");
        exit(1);
    }
    @unlink($tmpMerge);
}

// Lock — no direct edits; all changes must go through split → merge
chmod($indexFile, 0444);

$mergedLines = substr_count($merged, "\n") + 1;
echo "✓ index.php updated: {$mergedLines} lines.\n";

// Write sentinel so the pre-commit hook knows the merge ran successfully.
// If VS Code phantom-writes any section file back, the hook sees this sentinel
// and auto-cleans tmp/sections/ rather than blocking the commit.
file_put_contents($root . '/tmp/.merge-done', date('Y-m-d H:i:s'));

// ── Clean up section files ────────────────────────────────────────────────────

$deleted = 0;
foreach ($files as $file) {
    if (@unlink($file)) {
        $deleted++;
    }
}
// Sweep any stragglers (editor swap/temp files, VS Code phantom-writes, etc.)
// that glob('s*.php') didn't match, so rmdir can always succeed.
if (is_dir($sectionsDir)) {
    foreach (scandir($sectionsDir) as $item) {
        if ($item !== '.' && $item !== '..') {
            @unlink($sectionsDir . '/' . $item);
        }
    }
    @rmdir($sectionsDir);
}
echo "✓ Cleaned up: {$deleted} section file(s) removed, tmp/sections/ deleted.\n";

// ── Prune old backups — keep 10 most recent ───────────────────────────────────

$allBackups = glob($backupsDir . '/index_*.php');
if (is_array($allBackups) && count($allBackups) > 10) {
    sort($allBackups); // lex sort = chronological (timestamp in filename)
    $toDelete = array_slice($allBackups, 0, count($allBackups) - 10);
    $pruned   = 0;
    foreach ($toDelete as $old) {
        if (@unlink($old)) {
            $pruned++;
        }
    }
    if ($pruned > 0) {
        echo "✓ Pruned {$pruned} old backup(s) — keeping 10 most recent.\n";
    }
}

echo "\n✓ Merge complete. Run dev server to verify:\n";
echo "    php -S 127.0.0.1:8080 index.php\n\n";
