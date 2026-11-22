<?php

declare(strict_types=1);

namespace Fedale\PackageFormat\Tests\Versions;

use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentCodec;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\FieldTypes\FieldTypeCatalog;
use Fedale\PackageFormat\Package;
use Fedale\PackageFormat\PackageDirectory;
use Fedale\PackageFormat\Versions\ItemStates;
use Fedale\PackageFormat\Versions\VersionCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a version's number fits what it changed, item by item, against an earlier version.
 */
final class VersionCheckTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../Fixtures/shop';

    /**
     * @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $change   the new fields of objects/product.yaml
     * @param list<string>                                                     $expected
     */
    #[DataProvider('changes')]
    public function testANumberMustFitTheChanges(\Closure $change, string $to, array $expected): void
    {
        $base = self::fixture();
        $next = self::withProduct($base, $change);

        self::assertSame($expected, self::problems($base, $next, '1.0.0', $to));
    }

    /**
     * @return iterable<string, array{\Closure(list<array<string, mixed>>): list<array<string, mixed>>, string, list<string>}>
     */
    public static function changes(): iterable
    {
        $same = self::same(...);
        $relabel = self::relabel(...);
        $add = self::add(...);
        $setting = self::setting(...);
        $remove = self::remove(...);
        $rename = self::rename(...);

        yield 'nothing, a patch' => [$same, '1.0.1', []];
        yield 'a label, a patch' => [$relabel, '1.0.1', []];
        yield 'an added field, a patch' => [$add, '1.0.1', ['objects/product.yaml: field acme.product.notes: an addition needs a new minor version, 1.0.1 is not one.']];
        yield 'an added field, a minor' => [$add, '1.1.0', []];
        yield 'a setting, a patch' => [$setting, '1.0.1', ['objects/product.yaml: field acme.product.name: a change of settings needs a new minor version, 1.0.1 is not one.']];
        yield 'a setting, a minor' => [$setting, '1.1.0', []];
        yield 'a removed field, a minor' => [$remove, '1.1.0', ['objects/product.yaml: field acme.product.photo: a removal needs a new major version, 1.1.0 is not one.']];
        yield 'a renamed field, a minor' => [$rename, '1.1.0', ['objects/product.yaml: field acme.product.name: a rename needs a new major version, 1.1.0 is not one.']];
        yield 'a renamed field, a major' => [$rename, '2.0.0', []];
        yield 'a removed field, a major' => [$remove, '2.0.0', []];
    }

    public function testMigrationStepsNeedAMajorVersion(): void
    {
        $base = self::fixture();

        self::assertSame(
            ['package.yaml: spec.migrations: migration steps need a new major version, 1.1.0 is not one.'],
            VersionCheck::problems(self::states($base), self::states($base), '1.0.0', '1.1.0', true),
        );
    }

    public function testATranslationComesAndGoesInAPatch(): void
    {
        $base = self::fixture();
        $without = new Package($base->manifest, array_values(array_filter($base->documents, static fn (Document $document): bool => DocumentKind::Translations !== $document->kind)), $base->directory);

        self::assertSame([], self::problems($base, $without, '1.0.0', '1.0.1'));
        self::assertSame([], self::problems($without, $base, '1.0.0', '1.0.1'));
    }

    /**
     * A workflow: its description in a patch, its definition in a minor, its name in a major
     * (the workflows calling it name it); added in a minor, removed in a major.
     */
    public function testAWorkflowsChangesFitTheirNumber(): void
    {
        $base = self::fixture();
        $described = self::withWorkflow($base, static fn (array $spec): array => ['description' => 'Restocks.'] + $spec);
        $changed = self::withWorkflow($base, static fn (array $spec): array => ['definition' => ['trigger' => ['on' => 'manual', 'object' => 'acme.product', 'label' => 'Restock'], 'steps' => [['key' => 'a', 'type' => 'refuse', 'message' => 'No.']]]] + $spec);
        $renamed = self::withWorkflow($base, static fn (array $spec): array => ['name' => 'Restock now'] + $spec);
        $without = new Package($base->manifest, array_values(array_filter($base->documents, static fn (Document $document): bool => 'workflows/restock.yaml' !== $document->path)), $base->directory);

        self::assertSame([], self::problems($base, $described, '1.0.0', '1.0.1'));
        self::assertSame(['workflows/restock.yaml: workflow acme.workflow.restock: a change of definition needs a new minor version, 1.0.1 is not one.'], self::problems($base, $changed, '1.0.0', '1.0.1'));
        self::assertSame([], self::problems($base, $changed, '1.0.0', '1.1.0'));
        self::assertSame(['workflows/restock.yaml: workflow acme.workflow.restock: a rename needs a new major version, 1.1.0 is not one.'], self::problems($base, $renamed, '1.0.0', '1.1.0'));
        self::assertSame(['workflows/restock.yaml: workflow acme.workflow.restock: a removal needs a new major version, 1.1.0 is not one.'], self::problems($base, $without, '1.0.0', '1.1.0'));
        self::assertSame(['workflows/restock.yaml: workflow acme.workflow.restock: an addition needs a new minor version, 1.0.1 is not one.'], self::problems($without, $base, '1.0.0', '1.0.1'));
        // Its keys in another order: the same definition.
        $reordered = self::withWorkflow($base, static fn (array $spec): array => ['definition' => array_reverse(\is_array($spec['definition']) ? $spec['definition'] : [], true)] + $spec);
        self::assertSame([], self::problems($base, $reordered, '1.0.0', '1.0.1'));
    }

    /**
     * Every table pointing at an object holds keys of its type: changing it is a major change. A
     * key left out (a version written before 2.4) is a UUID one.
     */
    public function testAChangeOfKeyNeedsAMajorVersion(): void
    {
        $base = self::fixture();
        $keyed = static fn (string $key): Package => new Package($base->manifest, array_map(
            static fn (Document $document): Document => 'objects/product.yaml' === $document->path ? new Document($document->kind, $document->id, ['key' => $key] + $document->spec, $document->path) : $document,
            $base->documents,
        ), $base->directory);

        self::assertSame([], self::problems($base, $keyed('uuid'), '1.0.0', '1.0.1'));
        self::assertSame(['objects/product.yaml: object acme.product: a change of key needs a new major version, 1.1.0 is not one.'], self::problems($base, $keyed('integer'), '1.0.0', '1.1.0'));
        self::assertSame([], self::problems($base, $keyed('integer'), '1.0.0', '2.0.0'));
    }

    /**
     * A setting written with its default value is the same item as one leaving it out.
     */
    public function testADefaultWrittenOutIsNoChange(): void
    {
        $base = self::fixture();
        $next = self::withProduct($base, self::defaultsWrittenOut(...));

        self::assertSame([], self::problems($base, $next, '1.0.0', '1.0.1'));
    }

    /**
     * @return list<string>
     */
    private static function problems(Package $base, Package $next, string $from, string $to): array
    {
        return VersionCheck::problems(self::states($base), self::states($next), $from, $to, false);
    }

    /**
     * @return array<string, \Fedale\PackageFormat\Versions\ItemState>
     */
    private static function states(Package $package): array
    {
        return (new ItemStates(new FieldTypeCatalog()))->of($package);
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change the new spec of workflows/restock.yaml
     */
    private static function withWorkflow(Package $package, \Closure $change): Package
    {
        return new Package($package->manifest, array_map(
            static fn (Document $document): Document => 'workflows/restock.yaml' === $document->path ? new Document($document->kind, $document->id, $change($document->spec), $document->path) : $document,
            $package->documents,
        ), $package->directory);
    }

    /**
     * @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $change
     */
    private static function withProduct(Package $package, \Closure $change): Package
    {
        return new Package($package->manifest, array_map(static function (Document $document) use ($change): Document {
            if ('objects/product.yaml' !== $document->path) {
                return $document;
            }
            $fields = [];
            foreach (\is_array($document->spec['fields'] ?? null) ? $document->spec['fields'] : [] as $field) {
                if (\is_array($field)) {
                    $fields[] = array_combine(array_map(strval(...), array_keys($field)), array_values($field));
                }
            }

            return new Document($document->kind, $document->id, ['fields' => $change($fields)] + $document->spec, $document->path);
        }, $package->documents), $package->directory);
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function same(array $fields): array
    {
        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function relabel(array $fields): array
    {
        $fields[0]['label'] = 'Title';

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function add(array $fields): array
    {
        $fields[] = ['id' => 'acme.product.notes', 'name' => 'notes', 'type' => 'text', 'label' => 'Notes'];

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function setting(array $fields): array
    {
        $fields[0]['settings'] = ['maxLength' => 80];

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function remove(array $fields): array
    {
        array_pop($fields);

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function rename(array $fields): array
    {
        $fields[0]['name'] = 'title';

        return $fields;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function defaultsWrittenOut(array $fields): array
    {
        $fields[0]['settings'] = ['maxLength' => 255, 'multiline' => false];

        return $fields;
    }

    private static function fixture(): Package
    {
        return (new PackageDirectory(new DocumentCodec()))->read(self::FIXTURE);
    }
}
