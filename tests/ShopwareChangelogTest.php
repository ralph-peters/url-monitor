<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShopwareChangelogTest extends TestCase
{
    public static function fixtureProvider(): array
    {
        return [
            '2026-01-01' => [
                'fixture'             => 'shopware-6-changelog-2026-01-01.html',
                'expectedVersions'    => ['6.5' => '6.5.8.18', '6.6' => '6.6.10.10', '6.7' => '6.7.5.1'],
                'nonSecurityVersions' => ['6.7.5.0', '6.7.4.2', '6.7.4.0', '6.6.10.8'],
            ],
            '2026-04-24' => [
                'fixture'             => 'shopware-6-changelog-2026-04-24.html',
                'expectedVersions'    => ['6.5' => '6.5.8.18', '6.6' => '6.6.10.15', '6.7' => '6.7.8.1'],
                'nonSecurityVersions' => ['6.7.9.0', '6.6.10.16', '6.7.8.2', '6.7.8.0'],
            ],
        ];
    }

    #[DataProvider('fixtureProvider')]
    public function testLatestSecurityVersionPerBranch(string $fixture, array $expectedVersions, array $nonSecurityVersions): void
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
        $result  = Monitor::extractShopwareSecurityVersions($content);

        $this->assertSame($expectedVersions, $result);
    }

    #[DataProvider('fixtureProvider')]
    public function testOnlyTracksKnownMajorBranches(string $fixture, array $expectedVersions, array $nonSecurityVersions): void
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
        $result  = Monitor::extractShopwareSecurityVersions($content);

        foreach (array_keys($result) as $branch) {
            $this->assertStringStartsWith('6.', $branch, "Unexpected branch: {$branch}");
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testNoFalsePositivesOnNonSecurityReleases(string $fixture, array $expectedVersions, array $nonSecurityVersions): void
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
        $result  = Monitor::extractShopwareSecurityVersions($content);
        $values  = array_values($result);

        foreach ($nonSecurityVersions as $version) {
            $this->assertNotContains(
                $version,
                $values,
                "{$version} should not be flagged as a security release"
            );
        }
    }
}
