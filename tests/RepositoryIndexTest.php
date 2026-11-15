<?php

declare(strict_types=1);

namespace Fedale\PackageFormat\Tests;

use Fedale\PackageFormat\IndexEntry;
use Fedale\PackageFormat\InvalidIndex;
use Fedale\PackageFormat\RepositoryIndex;
use PHPUnit\Framework\TestCase;

final class RepositoryIndexTest extends TestCase
{
    private const string ADDRESS = 'https://packages.acme.example/v1/index.json';

    public function testResolvesArchivesAgainstTheIndexAndPicksTheNewestUsableVersion(): void
    {
        $sha = str_repeat('a', 64);
        $index = RepositoryIndex::fromJson((string) json_encode(['format' => RepositoryIndex::FORMAT, 'packages' => ['acme/shop' => [
            '1.0.0' => ['url' => 'acme-shop-1.0.0.tar.gz', 'sha256' => $sha],
            '1.10.0' => ['url' => 'https://cdn.example/acme-shop-1.10.0.tar.gz', 'sha256' => $sha],
            '1.2.0' => ['url' => 'acme-shop-1.2.0.tar.gz', 'sha256' => $sha],
            '2.0.0' => ['url' => 'acme-shop-2.0.0.tar.gz', 'sha256' => $sha, 'yanked' => true],
            '1.11.0' => ['url' => 'acme-shop-1.11.0.tar.gz', 'sha256' => $sha, 'requires' => ['format' => 'metadata-driven/v2']],
            '3.0.0' => ['url' => 'acme-shop-3.0.0.tar.gz', 'sha256' => $sha, 'requires' => ['format' => 'metadata-driven/v3']],
        ]]]), self::ADDRESS);

        self::assertSame('https://packages.acme.example/v1/acme-shop-1.0.0.tar.gz', $index->find('acme/shop', '1.0.0')?->url);
        self::assertSame(['1.0.0', '1.2.0', '1.10.0', '1.11.0', '2.0.0', '3.0.0'], array_map(static fn (IndexEntry $entry): string => $entry->version, $index->versions('acme/shop')));
        // Not the yanked 2.0.0, not 3.0.0 of a format this library does not read: 1.11.0, of format
        // v2, newer than the v1 ones (an entry naming no format is v1), compared as versions.
        self::assertSame('1.11.0', $index->latest('acme/shop')?->version);
        self::assertSame('metadata-driven/v1', $index->find('acme/shop', '1.10.0')?->requires['format']);
        self::assertNull($index->latest('acme/blog'));
    }

    public function testRefusesWhatIsNotAnIndex(): void
    {
        foreach ([
            '{"packages": {}}' => 'is not of format metadata-driven/index/v1',
            '{"format": "metadata-driven/index/v1", "templates": {}}' => 'is not of format metadata-driven/index/v1',
            '{"format": "metadata-driven/index/v2", "packages": {}}' => 'is not of format metadata-driven/index/v1',
            '{"format": "metadata-driven/index/v1", "packages": {"Shop": {}}}' => 'lists "Shop", which is no package name',
            '{"format": "metadata-driven/index/v1", "packages": {"acme/shop": {"1.0.0": {"url": "a.tar.gz", "sha256": "abc"}}}}' => 'it needs a url and a sha256',
            'not json' => 'is not JSON',
        ] as $json => $message) {
            try {
                RepositoryIndex::fromJson($json, self::ADDRESS);
                self::fail($message);
            } catch (InvalidIndex $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testWritesItselfBackWithRelativeAddresses(): void
    {
        $entry = new IndexEntry('acme/shop', '1.0.0', 'https://packages.acme.example/v1/acme-shop-1.0.0.tar.gz', str_repeat('b', 64));

        $json = RepositoryIndex::empty()->with($entry)->toJson(self::ADDRESS);

        self::assertStringContainsString('"url": "acme-shop-1.0.0.tar.gz"', $json);
        self::assertEquals($entry, RepositoryIndex::fromJson($json, self::ADDRESS)->find('acme/shop', '1.0.0'));
    }
}
