<?php

namespace App\Services;

class CacheService
{
    /**
     * Clear all Twig compiled templates and temporary cache files.
     * Never touches database.sqlite or user uploads.
     */
    public static function clearAll(): array
    {
        $results = [
            'twig_cache_cleared' => false,
            'files_deleted' => 0,
            'opcache_cleared' => false,
            'messages' => []
        ];

        // Determine cache directory
        if (str_starts_with(__DIR__, 'phar://')) {
            $baseDir = dirname(\Phar::running(false));
            $cacheDir = $baseDir . '/data/cache';
        } else {
            $cacheDir = dirname(__DIR__, 2) . '/data/cache';
        }

        $twigDir = $cacheDir . '/twig';
        if (is_dir($twigDir)) {
            $deletedCount = self::deleteDirectoryContents($twigDir);
            $results['twig_cache_cleared'] = true;
            $results['files_deleted'] = $deletedCount;
            $results['messages'][] = "Cleared $deletedCount Twig cache files.";
        } else {
            @mkdir($twigDir, 0777, true);
            $results['twig_cache_cleared'] = true;
            $results['messages'][] = "Twig cache directory verified/created.";
        }

        // OPcache reset
        if (function_exists('opcache_reset')) {
            // Some shared hosts disable or restrict opcache_reset
            try {
                $opStatus = @opcache_reset();
                $results['opcache_cleared'] = (bool)$opStatus;
                $results['messages'][] = $opStatus ? "OPcache successfully reset." : "OPcache reset not permitted or already cleared.";
            } catch (\Throwable $e) {
                $results['messages'][] = "OPcache reset error: " . $e->getMessage();
            }
        } else {
            $results['messages'][] = "OPcache extension not active.";
        }

        // Update deploy version timestamp in data/cache/.deploy_version
        @file_put_contents($cacheDir . '/.deploy_version', time());

        return $results;
    }

    /**
     * Automatically invalidates stale caches if running from a newly deployed PHAR file.
     */
    public static function autoBustIfPharUpdated(): void
    {
        try {
            $pharPath = null;
            if (str_starts_with(__DIR__, 'phar://')) {
                $pharPath = \Phar::running(false);
                $baseDir = dirname($pharPath);
            } else {
                $baseDir = dirname(__DIR__, 2);
                if (file_exists($baseDir . '/app.phar')) {
                    $pharPath = $baseDir . '/app.phar';
                }
            }

            if (!$pharPath || !file_exists($pharPath)) {
                return;
            }

            $cacheDir = $baseDir . '/data/cache';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0777, true);
            }

            $stampFile = $cacheDir . '/.deploy_version';
            $lastDeployedTime = file_exists($stampFile) ? (int)@file_get_contents($stampFile) : 0;
            $currentPharTime = (int)@filemtime($pharPath);

            // If PHAR was updated after the last recorded deploy stamp
            if ($currentPharTime > $lastDeployedTime) {
                self::clearAll();
                @file_put_contents($stampFile, $currentPharTime);
            }
        } catch (\Throwable $e) {
            // Never break bootstrap if auto-bust check fails
        }
    }

    private static function deleteDirectoryContents(string $dir): int
    {
        $count = 0;
        if (!is_dir($dir)) {
            return 0;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $filename = $item->getFilename();
            if ($filename === '.gitkeep') {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                if (@unlink($item->getRealPath())) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
