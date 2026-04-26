<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShopwareSecurityPluginChangelogTest extends TestCase
{
    public static function fixtureProvider(): array
    {
        return [
            '2026-01-01' => [
                'fixture'          => 'shopware-6-security-plugin-changelog-2026-01-01.html',
                'expectedVersions' => ['6.5' => '2.0.14', '6.6' => '3.0.10', '6.7' => '4.0.4'],
            ],
            '2026-04-24' => [
                'fixture'          => 'shopware-6-security-plugin-changelog-2026-04-24.html',
                'expectedVersions' => ['6.5' => '2.0.19', '6.6' => '3.0.14', '6.7' => '4.0.9'],
            ],
        ];
    }

    #[DataProvider('fixtureProvider')]
    public function testLatestVersionPerBranch(string $fixture, array $expectedVersions): void
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
        $result  = Monitor::extractShopwareSecurityPluginVersions($content);

        $this->assertSame($expectedVersions, $result);
    }

    #[DataProvider('fixtureProvider')]
    public function testOnlyTracksKnownBranches(string $fixture, array $expectedVersions): void
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
        $result  = Monitor::extractShopwareSecurityPluginVersions($content);

        foreach (array_keys($result) as $branch) {
            $this->assertStringStartsWith('6.', $branch, "Unexpected branch: {$branch}");
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testTakesNewestVersionPerBranch(string $fixture, array $expectedVersions): void
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
        $result  = Monitor::extractShopwareSecurityPluginVersions($content);

        foreach ($expectedVersions as $branch => $version) {
            $this->assertSame($version, $result[$branch]);
        }
    }
}
