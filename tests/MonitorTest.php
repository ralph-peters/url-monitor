<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MonitorTest extends TestCase
{
    private static string $changelogFixture;
    private static string $pluginFixture;

    public static function setUpBeforeClass(): void
    {
        self::$changelogFixture = file_get_contents(__DIR__ . '/fixtures/shopware-6-changelog.html');
        self::$pluginFixture    = file_get_contents(__DIR__ . '/fixtures/shopware-6-security-plugin-changelog.html');
    }

    // -------------------------------------------------------------------------
    // extractShopwareSecurityVersions (shopware.com/nl/changelog)
    // -------------------------------------------------------------------------

    public function testLatestSecurityVersionPerBranch(): void
    {
        $result = Monitor::extractShopwareSecurityVersions(self::$changelogFixture);

        $this->assertSame([
            '6.5' => '6.5.8.18',
            '6.6' => '6.6.10.15',
            '6.7' => '6.7.8.1',
        ], $result);
    }

    public function testOnlyTracksKnownMajorBranches(): void
    {
        $result = Monitor::extractShopwareSecurityVersions(self::$changelogFixture);

        foreach (array_keys($result) as $branch) {
            $this->assertStringStartsWith('6.', $branch, "Unexpected branch: {$branch}");
        }
    }

    public function testNoFalsePositivesOnNonSecurityReleases(): void
    {
        $nonSecurityVersions = ['6.7.9.0', '6.6.10.16', '6.7.8.2', '6.7.8.0'];
        $result              = Monitor::extractShopwareSecurityVersions(self::$changelogFixture);
        $values              = array_values($result);

        foreach ($nonSecurityVersions as $version) {
            $this->assertNotContains(
                $version,
                $values,
                "{$version} should not be flagged as a security release"
            );
        }
    }

    // -------------------------------------------------------------------------
    // extractShopwareSecurityPluginVersions (store.shopware.com plugin)
    // -------------------------------------------------------------------------

    public function testLatestSecurityPluginVersionPerBranch(): void
    {
        $result = Monitor::extractShopwareSecurityPluginVersions(self::$pluginFixture);

        $this->assertSame([
            '6.5' => '2.0.19',
            '6.6' => '3.0.14',
            '6.7' => '4.0.9',
        ], $result);
    }

    public function testSecurityPluginOnlyTracksKnownBranches(): void
    {
        $result = Monitor::extractShopwareSecurityPluginVersions(self::$pluginFixture);

        foreach (array_keys($result) as $branch) {
            $this->assertStringStartsWith('6.', $branch, "Unexpected branch: {$branch}");
        }
    }

    public function testSecurityPluginTakesNewestVersionPerBranch(): void
    {
        $result = Monitor::extractShopwareSecurityPluginVersions(self::$pluginFixture);

        // 2.0.18 and 2.0.17 appear later in the changelog — only 2.0.19 should be returned
        $this->assertSame('2.0.19', $result['6.5']);
        $this->assertSame('3.0.14', $result['6.6']);
        $this->assertSame('4.0.9', $result['6.7']);
    }
}
