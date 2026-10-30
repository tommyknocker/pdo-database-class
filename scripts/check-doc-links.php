<?php

declare(strict_types=1);

/** Check local Markdown links and heading fragments without network access. */
$root = dirname(__DIR__);
$files = [$root . '/README.md', $root . '/CONTRIBUTING.md', $root . '/tests/README.md'];
foreach (['documentation', 'examples'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[] = $file->getPathname();
        }
    }
}

$anchors = [];
$errors = [];
$checked = 0;
foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $errors[] = "Unable to read {$file}";
        continue;
    }
    $inFence = false;
    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*(```+|~~~+)/', $line) === 1) {
            $inFence = !$inFence;
            continue;
        }
        if ($inFence) {
            continue;
        }
        preg_match_all('/\]\(([^)]+)\)/', $line, $matches);
        foreach ($matches[1] as $destination) {
            $destination = preg_replace('/\s+"[^"]*"$/', '', $destination);
            if ($destination === null || preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|<)~i', $destination) === 1 || str_contains($destination, '{')) {
                continue;
            }
            [$path, $fragment] = array_pad(explode('#', $destination, 2), 2, '');
            $target = $path === '' ? $file : dirname($file) . '/' . rawurldecode($path);
            ++$checked;
            if (!file_exists($target)) {
                $errors[] = substr($file, strlen($root) + 1) . ':' . ($index + 1) . " missing path: {$destination}";
                continue;
            }
            if ($fragment === '' || !is_file($target) || pathinfo($target, PATHINFO_EXTENSION) !== 'md') {
                continue;
            }
            $target = realpath($target);
            if ($target === false) {
                continue;
            }
            if (!isset($anchors[$target])) {
                $anchors[$target] = [];
                $counts = [];
                $fenced = false;
                foreach (file($target, FILE_IGNORE_NEW_LINES) ?: [] as $heading) {
                    if (preg_match('/^\s*(```+|~~~+)/', $heading) === 1) {
                        $fenced = !$fenced;
                        continue;
                    }
                    if (!$fenced && preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/u', $heading, $m) === 1) {
                        $slug = function_exists('mb_strtolower') ? mb_strtolower($m[1], 'UTF-8') : strtolower($m[1]);
                        $slug = str_replace(' ', '-', $slug);
                        $slug = preg_replace('/[^\p{L}\p{N}_-]/u', '', $slug) ?? '';
                        $count = $counts[$slug] ?? 0;
                        $counts[$slug] = $count + 1;
                        $anchors[$target][$slug . ($count > 0 ? '-' . $count : '')] = true;
                    }
                    if (preg_match_all('/<a\s+(?:id|name)=["\']([^"\']+)["\']/i', $heading, $ids) > 0) {
                        foreach ($ids[1] as $id) {
                            $anchors[$target][$id] = true;
                        }
                    }
                }
            }
            if (!isset($anchors[$target][rawurldecode($fragment)])) {
                $errors[] = substr($file, strlen($root) + 1) . ':' . ($index + 1) . " missing heading: {$destination}";
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "Checked {$checked} local links in " . count($files) . " Markdown files.\n";
