<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ShopwareSecurityPluginChangelogTest extends TestCase
{
    private static string $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = file_get_contents(__DIR__ . '/fixtures/shopware-6-security-plugin-changelog.html');
    }

    public function testLatestVersionPerBranch(): void
    {
        $result = Monitor::extractShopwareSecurityPluginVersions(self::$fixture);

        $this->assertSame([
            '6.5' => '2.0.19',
            '6.6' => '3.0.14',
            '6.7' => '4.0.9',
        ], $result);
    }

    public function testOnlyTracksKnownBranches(): void
    {
        $result = Monitor::extractShopwareSecurityPluginVersions(self::$fixture);

        foreach (array_keys($result) as $branch) {
            $this->assertStringStartsWith('6.', $branch, "Unexpected branch: {$branch}");
        }
    }

    public function testTakesNewestVersionPerBranch(): void
    {
        $result = Monitor::extractShopwareSecurityPluginVersions(self::$fixture);

        // 2.0.18 and 2.0.17 appear later in the changelog — only 2.0.19 should be returned
        $this->assertSame('2.0.19', $result['6.5']);
        $this->assertSame('3.0.14', $result['6.6']);
        $this->assertSame('4.0.9', $result['6.7']);
    }
}
