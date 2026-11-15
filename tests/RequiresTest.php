<?php

declare(strict_types=1);

namespace Fedale\PackageFormat\Tests;

use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentCodec;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\FieldTypes\FieldTypeCatalog;
use Fedale\PackageFormat\Package;
use Fedale\PackageFormat\PackageChecker;
use Fedale\PackageFormat\PackageDirectory;
use Fedale\PackageFormat\SchemaValidator;
use Fedale\PackageFormat\SeedRecords;
use PHPUnit\Framework\TestCase;

/**
 * A package requiring another (acme/people requires acme/places ^1.0): it references the
 * required package's objects, fields and seed records, and only references them.
 */
final class RequiresTest extends TestCase
{
    public function testReferencesTheRequiredPackage(): void
    {
        self::assertSame([], self::problems(self::people(), [self::places()]));
    }

    public function testTheRequiredPackageMustBeGiven(): void
    {
        self::assertContains(
            'package.yaml: spec.requires.acme/places: acme/places is required: it must be installed, or given to check against.',
            self::problems(self::people()),
        );
    }

    public function testTheRequiredVersionMustFit(): void
    {
        $people = self::withManifest(self::people(), static fn (array $spec): array => ['requires' => ['format' => 'metadata-driven/v2', 'acme/places' => '^2.0']] + $spec);

        self::assertContains('package.yaml: spec.requires.acme/places: acme/places 1.2.0 does not fit ^2.0.', self::problems($people, [self::places()]));
    }

    public function testTheConstraintMustBeValid(): void
    {
        $people = self::withManifest(self::people(), static fn (array $spec): array => ['requires' => ['format' => 'metadata-driven/v2', 'acme/places' => '>=1.0']] + $spec);

        self::assertContains(
            'package.yaml: spec.requires.acme/places: ">=1.0" is not a version constraint: ^1.2, ~1.2 or an exact 1.2.3.',
            self::problems($people, [self::places()]),
        );
    }

    public function testAPackageDoesNotRequireItself(): void
    {
        $people = self::withManifest(self::people(), static fn (array $spec): array => ['requires' => ['format' => 'metadata-driven/v2', 'acme/people' => '^1.0']] + $spec);

        self::assertContains('package.yaml: spec.requires.acme/people: A package does not require itself.', self::problems($people));
    }

    public function testItsIdsDifferFromTheRequiredOnes(): void
    {
        $people = self::with(self::people(), new Document(DocumentKind::Object, 'acme.region', [
            'name' => 'area',
            'label' => 'Area',
            'plural' => 'Areas',
            'fields' => [['id' => 'acme.region.name', 'name' => 'name', 'type' => 'text', 'label' => 'Name']],
        ], 'objects/area.yaml'));

        $problems = self::problems($people, [self::places()]);
        self::assertContains('objects/area.yaml: id: Already the id of an item of acme/places, which this package requires.', $problems);
        self::assertContains('objects/area.yaml: spec.fields.0.id: Already the id of a field of acme/places, which this package requires.', $problems);
    }

    public function testItOnlyReferencesTheRequiredObjects(): void
    {
        $people = self::with(self::with(self::people(), new Document(DocumentKind::View, 'acme.view.region.grid.main', [
            'object' => 'acme.region',
            'kind' => 'grid',
            'name' => 'main',
            'source' => "columns: [name]\n",
        ], 'views/region.grid.main.yaml')), new Document(DocumentKind::Seed, 'acme.seed.region_more', [
            'object' => 'acme.region',
            'key' => 'code',
            'records' => [['code' => '03', 'name' => 'East']],
        ], 'seeds/region.yaml'));

        $problems = self::problems($people, [self::places()]);
        self::assertContains('views/region.grid.main.yaml: spec.object: "acme.region" is an object of acme/places: a package adds views to its own objects only.', $problems);
        self::assertContains('seeds/region.yaml: spec.object: "acme.region" is an object of acme/places: a package seeds its own objects only.', $problems);
    }

    public function testASeedPointsAtRecordsOfTheRequiredSeed(): void
    {
        $people = self::withDocument(self::people(), 'seeds/person.yaml', static fn (array $spec): array => ['records' => [['email' => 'ada@example.com', 'region' => '99']]] + $spec);

        self::assertContains(
            'seeds/person.yaml: spec.records.0.region: No record of the seed of "acme.region" has the key "99".',
            self::problems($people, [self::places()]),
        );
    }

    public function testWithoutTheRequirementItsObjectsAreUnknown(): void
    {
        $people = self::withManifest(self::people(), static fn (array $spec): array => ['requires' => ['format' => 'metadata-driven/v2']] + $spec);

        self::assertContains(
            'objects/person.yaml: spec.fields.1.settings.target: No object of the package has the id "acme.region".',
            self::problems($people, [self::places()]),
        );
    }

    /**
     * @param list<Package> $required
     *
     * @return list<string>
     */
    private static function problems(Package $package, array $required = []): array
    {
        $checker = new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords());

        return array_map(strval(...), $checker->check($package, $required));
    }

    private static function people(): Package
    {
        return (new PackageDirectory(new DocumentCodec()))->read(__DIR__.'/Fixtures/people');
    }

    private static function places(): Package
    {
        return (new PackageDirectory(new DocumentCodec()))->read(__DIR__.'/Fixtures/places');
    }

    private static function with(Package $package, Document $document): Package
    {
        return new Package($package->manifest, [...$package->documents, $document], $package->directory);
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    private static function withManifest(Package $package, \Closure $change): Package
    {
        $manifest = $package->manifest;

        return new Package(new Document($manifest->kind, $manifest->id, $change($manifest->spec), $manifest->path), $package->documents, $package->directory);
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    private static function withDocument(Package $package, string $path, \Closure $change): Package
    {
        return new Package($package->manifest, array_map(
            static fn (Document $document): Document => $document->path === $path ? new Document($document->kind, $document->id, $change($document->spec), $document->path) : $document,
            $package->documents,
        ), $package->directory);
    }
}
