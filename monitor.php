<?php

declare(strict_types=1);

/**
 * URL change monitor.
 *
 * Fetches each URL, hashes its content, and compares against the last known hash.
 * Sends a Slack notification when a change is detected.
 * Hashes are stored as JSON files in .cache/ so GitHub Actions can persist them
 * via the cache commit step.
 *
 * Some URLs use custom extractors instead of full-page hashing. These extract a
 * specific signal from the page (e.g. only security-tagged releases) so that
 * unrelated page changes don't trigger false positives.
 */
class Monitor
{
    public const CACHE_DIR = '.cache';

    // Set to true to print Slack messages without actually sending them.
    public const SLACK_DRY_RUN = true;

    // Maps URLs to the name of their custom extractor method on this class.
    private const EXTRACTORS = [
        'https://www.shopware.com/nl/changelog/' => 'extractShopwareSecurityVersions',
    ];

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public static function run(array $urls): void
    {
        foreach ($urls as $url) {
            self::checkUrl($url);
        }
        echo "\nDone.\n";
    }

    // -------------------------------------------------------------------------
    // Check logic
    // -------------------------------------------------------------------------

    public static function checkUrl(string $url): void
    {
        echo "\nChecking: {$url}\n";
        $content = self::fetchContent($url);
        if ($content === null) {
            return;
        }

        $extractor = self::EXTRACTORS[$url] ?? null;
        if ($extractor !== null) {
            self::checkUrlWithExtractor($url, $content, $extractor);
        } else {
            self::checkUrlHash($url, $content);
        }
    }

    private static function checkUrlHash(string $url, string $content): void
    {
        $currentHash = self::hashContent($content);
        $cached      = self::loadCached($url);

        if ($cached === null) {
            echo "  [NEW] No previous record found — saving baseline.\n";
            self::saveCache($url, ['hash' => $currentHash]);
            self::sendSlackNotification(
                $url,
                ":eyes: *URL Monitor* is now tracking:\n<{$url}|{$url}>\n"
                . '_First check completed at ' . self::now() . ' — future changes will trigger a notification._'
            );
        } elseif (($cached['hash'] ?? null) !== $currentHash) {
            echo "  [CHANGED] Hash mismatch detected!\n";
            echo '    Old: ' . substr($cached['hash'] ?? '', 0, 16) . "…\n";
            echo '    New: ' . substr($currentHash, 0, 16) . "…\n";
            self::saveCache($url, ['hash' => $currentHash]);
            self::sendSlackNotification(
                $url,
                ":bell: *Page changed!*\n<{$url}|{$url}>\n_Detected at " . self::now() . '_'
            );
        } else {
            echo '  [OK] No change detected (hash: ' . substr($currentHash, 0, 16) . "…)\n";
        }
    }

    private static function checkUrlWithExtractor(string $url, string $content, string $extractorMethod): void
    {
        $currentValue = call_user_func([self::class, $extractorMethod], $content);
        $cached       = self::loadCached($url);

        if ($cached === null || !array_key_exists('extracted', $cached)) {
            echo "  [NEW] No previous record found — saving baseline.\n";
            echo '    Extracted: ' . json_encode($currentValue) . "\n";
            self::saveCache($url, ['extracted' => $currentValue]);
            self::sendSlackNotification(
                $url,
                ":eyes: *URL Monitor* is now tracking:\n<{$url}|{$url}>\n_Baseline set at " . self::now() . '._'
            );
            return;
        }

        $previousValue = $cached['extracted'];

        if (is_array($currentValue) && is_array($previousValue)) {
            $changed = [];
            foreach ($currentValue as $branch => $newVer) {
                $oldVer = $previousValue[$branch] ?? null;
                if ($newVer !== $oldVer) {
                    $changed[$branch] = [$oldVer, $newVer];
                }
            }

            if (!empty($changed)) {
                echo '  [CHANGED] New security versions: ' . json_encode($changed) . "\n";
                self::saveCache($url, ['extracted' => $currentValue]);
                ksort($changed);
                $lines = implode("\n", array_map(
                    fn ($branch, $pair) => "  • {$branch}: `{$pair[1]}`  (was `{$pair[0]}`)",
                    array_keys($changed),
                    array_values($changed)
                ));
                self::sendSlackNotification(
                    $url,
                    ":rotating_light: *Shopware Security Update(s) released!*\n<{$url}|{$url}>\n"
                    . $lines . "\n_Detected at " . self::now() . '_'
                );
            } else {
                echo '  [OK] No new security versions: ' . json_encode($currentValue) . "\n";
            }
        } else {
            if ($currentValue !== $previousValue) {
                echo "  [CHANGED] Extracted value changed.\n";
                self::saveCache($url, ['extracted' => $currentValue]);
                self::sendSlackNotification(
                    $url,
                    ":bell: *Page changed!*\n<{$url}|{$url}>\n_Detected at " . self::now() . '_'
                );
            } else {
                echo "  [OK] No change detected.\n";
            }
        }
    }

    // -------------------------------------------------------------------------
    // Custom extractors
    // -------------------------------------------------------------------------

    /**
     * Return the latest security-tagged release per major Shopware branch.
     *
     * The changelog page lists releases newest-first, so the first security
     * entry found per major branch (e.g. "6.5", "6.6", "6.7") is the latest.
     *
     * Returns e.g. ['6.5' => '6.5.8.18', '6.6' => '6.6.10.15', '6.7' => '6.7.8.1'].
     */
    public static function extractShopwareSecurityVersions(string $content): array
    {
        preg_match_all(
            '/release-title--version[^>]*>(\d+\.\d+\.\d+(?:\.\d+)?)/',
            $content,
            $allMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        $versionPositions = [];
        foreach ($allMatches as $match) {
            $versionPositions[] = [
                'pos'     => $match[0][1],  // byte offset of full match
                'version' => $match[1][0],  // captured version string
            ];
        }

        $latestPerBranch = [];
        foreach ($versionPositions as $i => $item) {
            $pos     = $item['pos'];
            $version = $item['version'];
            $nextPos = $versionPositions[$i + 1]['pos'] ?? $pos + 5000;
            $segment = substr($content, $pos, min(3000, $nextPos - $pos));

            if (str_contains($segment, 'release-title--security')) {
                $parts  = explode('.', $version);
                $branch = $parts[0] . '.' . $parts[1];  // e.g. "6.7"
                if (!isset($latestPerBranch[$branch])) {
                    $latestPerBranch[$branch] = $version;
                }
            }
        }

        ksort($latestPerBranch);

        return $latestPerBranch;
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    public static function fetchContent(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout'        => 15,
                'header'         => 'User-Agent: Mozilla/5.0 (URL Monitor)',
                'ignore_errors'  => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            echo "  [ERROR] Could not fetch {$url}: {$error}\n";
            return null;
        }

        return $content;
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public static function hashContent(string $content): string
    {
        return hash('sha256', $content);
    }

    public static function cachePath(string $url): string
    {
        return self::CACHE_DIR . '/' . hash('md5', $url) . '.json';
    }

    public static function loadCached(string $url): ?array
    {
        $path = self::cachePath($url);
        if (!file_exists($path)) {
            return null;
        }
        return json_decode(file_get_contents($path), true);
    }

    public static function saveCache(string $url, array $data): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
        $payload = array_merge(
            [
                'url'          => $url,
                'last_checked' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
            ],
            $data
        );
        file_put_contents(
            self::cachePath($url),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    public static function sendSlackNotification(string $url, string $message): void
    {
        if (self::SLACK_DRY_RUN) {
            echo "  [DRY RUN] Would send to Slack:\n{$message}\n";
            return;
        }

        $webhookUrl = getenv('SLACK_WEBHOOK_URL') ?: '';
        if ($webhookUrl === '') {
            echo "  [WARN] SLACK_WEBHOOK_URL not set — skipping notification.\n";
            return;
        }

        $payload = json_encode(['text' => $message]);
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => 10,
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
                'content'       => $payload,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($webhookUrl, false, $context);
        if ($response === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            echo "  [ERROR] Failed to send Slack notification: {$error}\n";
        } else {
            echo "  [SLACK] Notification sent.\n";
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function now(): string
    {
        return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i') . ' UTC';
    }
}

// Run when invoked directly from the CLI; skipped when included by tests.
if (!defined('RUNNING_TESTS')) {
    $urls = array_slice($argv, 1);
    if (empty($urls)) {
        echo "Usage: php monitor.php <url1> [url2] ...\n";
        exit(1);
    }
    Monitor::run($urls);
}
