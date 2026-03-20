<?php
/**
 * MonolithCMS — Split Script
 *
 * Splits index.php into per-section files in tmp/sections/.
 * Run before AI agents make targeted changes to a section.
 *
 * Usage:  php scripts/split.php
 *
 * Output: tmp/sections/s01-config-boot.php … s24-route-definitions.php
 *
 * CONVENTION (see CLAUDE.md §Section Convention):
 *   Section headers in index.php must follow this exact 3-line format:
 *     // ─────────────────────────────────────────────────────────────────────────────
 *     // SECTION N: NAME IN UPPER CASE
 *     // ─────────────────────────────────────────────────────────────────────────────
 *   Numbers are sequential integers (no letters, no gaps).
 *   This script uses a counter — NOT the number from the comment — to name files,
 *   so file ordering is always reliable regardless of comment content.
 */

$root       = dirname(__DIR__);
$sourceFile = $root . '/index.php';
$outDir     = $root . '/tmp/sections';

// ── Preflight ────────────────────────────────────────────────────────────────

if (is_dir($outDir)) {
    fwrite(STDERR, "\nERROR: tmp/sections/ already exists.\n");
    fwrite(STDERR, "       Did you forget to merge? Run: php scripts/merge.php\n\n");
    exit(1);
}

// Remove any leftover sentinel from a previous merge session.
@unlink(dirname($outDir) . '/.merge-done');

if (!file_exists($sourceFile)) {
    fwrite(STDERR, "\nERROR: index.php not found at {$sourceFile}\n\n");
    exit(1);
}

// ── Read source ──────────────────────────────────────────────────────────────

$lines = file($sourceFile, FILE_BINARY);
if ($lines === false) {
    fwrite(STDERR, "\nERROR: Could not read index.php\n\n");
    exit(1);
}

$totalLines = count($lines);

// ── Boundary detection ───────────────────────────────────────────────────────
// Match the ─ divider lines (U+2500 box-drawing character) and SECTION header.

$dividerRe = '/^\/\/ \x{2500}{5,}\s*$/u';
$sectionRe = '/^\/\/ SECTION \d+: (.+?)\s*$/u';

$chunks    = [];  // ['name' => string, 'slug' => string, 'lines' => string[]]
$preamble  = [];  // lines before the first section header
$current   = null;
$inSection = false;

$i = 0;
while ($i < $totalLines) {
    $line = $lines[$i];

    // Look ahead: divider + SECTION header + divider = boundary
    if (
        preg_match($dividerRe, rtrim($line)) &&
        isset($lines[$i + 1]) &&
        preg_match($sectionRe, $lines[$i + 1], $m) &&
        isset($lines[$i + 2]) &&
        preg_match($dividerRe, rtrim($lines[$i + 2]))
    ) {
        // Close previous chunk
        if ($current !== null) {
            $chunks[] = $current;
        }

        // Derive slug from the section name
        $sectionName = trim($m[1]);
        $slug        = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $sectionName), '-'));

        $current   = ['name' => $sectionName, 'slug' => $slug, 'lines' => []];
        $inSection = true;

        // Include all three header lines in the new chunk
        $current['lines'][] = $lines[$i];
        $current['lines'][] = $lines[$i + 1];
        $current['lines'][] = $lines[$i + 2];
        $i += 3;
        continue;
    }

    if (!$inSection) {
        $preamble[] = $line;
    } elseif ($current !== null) {
        $current['lines'][] = $line;
    }

    $i++;
}

// Save final chunk
if ($current !== null) {
    $chunks[] = $current;
}

if (empty($chunks)) {
    fwrite(STDERR, "\nERROR: No SECTION headers found in index.php.\n");
    fwrite(STDERR, "       Check that headers follow the 3-line convention in CLAUDE.md.\n\n");
    exit(1);
}

// Prepend preamble (<?php docblock + CLI server block) to the first section file
$chunks[0]['lines'] = array_merge($preamble, $chunks[0]['lines']);

// ── Write output ─────────────────────────────────────────────────────────────

if (!mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "\nERROR: Could not create directory: {$outDir}\n\n");
    exit(1);
}

$summary     = [];
$splitTotal  = 0;

foreach ($chunks as $idx => $chunk) {
    $num      = str_pad($idx + 1, 2, '0', STR_PAD_LEFT);
    $filename = "s{$num}-{$chunk['slug']}.php";
    $filepath = $outDir . '/' . $filename;
    $content  = implode('', $chunk['lines']);
    $lc       = count($chunk['lines']);

    if (file_put_contents($filepath, $content, LOCK_EX) === false) {
        fwrite(STDERR, "\nERROR: Could not write {$filepath}\n\n");
        exit(1);
    }

    $splitTotal += $lc;
    $summary[] = [$filename, 'SECTION ' . ($idx + 1) . ': ' . $chunk['name'], $lc];
}

// ── Report ────────────────────────────────────────────────────────────────────

$countMatch = ($splitTotal === $totalLines);

echo "\n";
echo "✓ Split complete — " . count($chunks) . " section files in tmp/sections/\n\n";
printf("  %-32s %-42s %7s\n", 'File', 'Section', 'Lines');
echo '  ' . str_repeat('─', 83) . "\n";
foreach ($summary as [$file, $section, $lc]) {
    printf("  %-32s %-42s %7d\n", $file, $section, $lc);
}
echo '  ' . str_repeat('─', 83) . "\n";
printf("  %-32s %-42s %7d\n", '', 'TOTAL (split)', $splitTotal);
printf("  %-32s %-42s %7d\n", '', 'ORIGINAL index.php', $totalLines);
echo "\n";

if (!$countMatch) {
    echo "  ⚠  WARNING: Line count mismatch ({$splitTotal} vs {$totalLines}). Check before editing!\n\n";
} else {
    echo "  ✓  Line counts match — safe to edit.\n\n";
}

echo "  Edit the relevant s*.php file(s), then run:\n";
echo "    php scripts/merge.php\n\n";
