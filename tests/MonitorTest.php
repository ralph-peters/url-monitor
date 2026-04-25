<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MonitorTest extends TestCase
{
    private static string $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = file_get_contents(__DIR__ . '/fixtures/shopware-6-changelog.html');
    }

    public function testLatestSecurityVersionPerBranch(): void
    {
        $result = Monitor::extractShopwareSecurityVersions(self::$fixture);

        $this->assertSame([
            '6.5' => '6.5.8.18',
            '6.6' => '6.6.10.15',
            '6.7' => '6.7.8.1',
        ], $result);
    }

    public function testOnlyTracksKnownMajorBranches(): void
    {
        $result = Monitor::extractShopwareSecurityVersions(self::$fixture);

        foreach (array_keys($result) as $branch) {
            $this->assertStringStartsWith('6.', $branch, "Unexpected branch: {$branch}");
        }
    }

    public function testNoFalsePositivesOnNonSecurityReleases(): void
    {
        $nonSecurityVersions = ['6.7.9.0', '6.6.10.16', '6.7.8.2', '6.7.8.0'];
        $result              = Monitor::extractShopwareSecurityVersions(self::$fixture);
        $values              = array_values($result);

        foreach ($nonSecurityVersions as $version) {
            $this->assertNotContains(
                $version,
                $values,
                "{$version} should not be flagged as a security release"
            );
        }
    }
}
