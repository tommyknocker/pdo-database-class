<?php

declare(strict_types=1);

namespace tommyknocker\pdodb;

use Composer\InstalledVersions;

/**
 * Version of this package, for the CLI banner and the MCP server info.
 */
final class PackageVersion
{
    /**
     * Installed version from Composer, else the nearest git tag of a source checkout, else 'dev'.
     */
    public static function detect(): string
    {
        // Try to get version from Composer\InstalledVersions
        if (class_exists(InstalledVersions::class)) {
            try {
                $version = InstalledVersions::getVersion('tommyknocker/pdo-database-class');
                if (is_string($version) && $version !== '') {
                    // Remove 'dev-' prefix and commit hash for dev versions
                    $version = preg_replace('/^dev-[^-]+-/', '', $version);
                    $version = is_string($version) ? preg_replace('/^dev-/', '', $version) : '';
                    // If it's a valid version (not just 'master'), return it
                    if (is_string($version) && preg_match('/^\d+\.\d+\.\d+/', $version) === 1) {
                        return $version;
                    }
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        // Try to get version from git
        $gitDir = __DIR__ . '/../.git';
        if (is_dir($gitDir)) {
            // First try to get exact tag
            $gitDescribe = @shell_exec('git describe --tags --exact-match 2>/dev/null');
            if (is_string($gitDescribe) && $gitDescribe !== '') {
                $version = trim($gitDescribe);
                $version = ltrim($version, 'v');
                if (preg_match('/^\d+\.\d+\.\d+/', $version) === 1) {
                    return $version;
                }
            }

            // If not on exact tag, get the latest tag
            $gitDescribe = @shell_exec('git describe --tags --always 2>/dev/null');
            if (is_string($gitDescribe) && $gitDescribe !== '') {
                $version = trim($gitDescribe);
                $version = ltrim($version, 'v');
                // Extract just the version number from "2.10.2-12-gabc123" -> "2.10.2"
                $matches = [];
                if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches) === 1 && count($matches) > 1) {
                    return (string)$matches[1];
                }
            }
        }

        // Fallback: try to read from composer.json in parent directory
        $composerPath = __DIR__ . '/../composer.json';
        if (file_exists($composerPath)) {
            $composerContent = @file_get_contents($composerPath);
            if (is_string($composerContent)) {
                $composer = @json_decode($composerContent, true);
                if (is_array($composer) && isset($composer['version']) && is_string($composer['version'])) {
                    return $composer['version'];
                }
            }
        }

        // Not a release checkout and no metadata: say so rather than name a version that may be wrong
        return 'dev';
    }
}
