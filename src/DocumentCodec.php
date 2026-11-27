<?php

declare(strict_types=1);

namespace Fedale\PackageFormat;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes one document as YAML. Reading checks the envelope only: what the spec holds
 * is the PackageChecker's business.
 *
 * A document of format v1 is read as its v2 equivalent, and every document is written in v2:
 * the manifest's kind Template is Package, a view's template is its source, and a manifest
 * requires format v2.
 */
final class DocumentCodec
{
    private const array ENVELOPE = ['format', 'kind', 'id', 'spec'];

    private const string V1 = 'metadata-driven/v1';

    /** The kind of the manifest in format v1. */
    private const string V1_MANIFEST_KIND = 'Template';

    /**
     * @throws InvalidPackage the YAML or the envelope is broken
     */
    public function decode(string $yaml, string $path): Document
    {
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new InvalidPackage([new Problem($path, '', new Text('yaml_invalid', 'Not valid YAML: %error%', ['error' => $e->getMessage()]))]);
        }
        if (!\is_array($data) || array_is_list($data) && [] !== $data) {
            throw new InvalidPackage([new Problem($path, '', new Text('document_invalid', 'A document is a map with the keys format, kind, id and spec.'))]);
        }

        $problems = [];
        foreach (array_keys(array_diff_key($data, array_flip(self::ENVELOPE))) as $key) {
            $problems[] = new Problem($path, (string) $key, new Text('document_key_unknown', 'Unknown key: a document has format, kind, id and spec only.'));
        }
        $format = $data['format'] ?? null;
        if (!\in_array($format, Format::READS, true)) {
            $problems[] = new Problem($path, 'format', new Text('format_invalid', 'Must be "%format%".', ['format' => Format::VERSION]));
        }
        $kind = \is_string($data['kind'] ?? null) ? self::kind($data['kind'], self::V1 === $format) : null;
        if (null === $kind) {
            $problems[] = new Problem($path, 'kind', new Text('one_of', 'Must be one of %values%.', ['values' => implode(', ', self::V1 === $format
                ? [self::V1_MANIFEST_KIND, ...array_diff(array_column(DocumentKind::cases(), 'value'), [DocumentKind::Package->value])]
                : array_column(DocumentKind::cases(), 'value'))]));
        }
        $id = $data['id'] ?? null;
        $pattern = DocumentKind::Package === $kind ? Format::NAME_PATTERN : Format::ID_PATTERN;
        if (!\is_string($id) || 1 !== preg_match($pattern, $id)) {
            $problems[] = new Problem($path, 'id', DocumentKind::Package === $kind
                ? new Text('manifest_id_invalid', 'The id of the manifest is the name of the package, vendor/name: lowercase letters, digits and hyphens.')
                : new Text('id_invalid', 'Lowercase dot-separated segments, at least two, the first naming the vendor: e.g. acme.product.'));
        }
        $spec = $data['spec'] ?? null;
        if (!\is_array($spec) || array_is_list($spec) && [] !== $spec) {
            $problems[] = new Problem($path, 'spec', new Text('not_a_map', 'Must be a map.'));
        }
        if ([] !== $problems || null === $kind || !\is_string($id) || !\is_array($spec)) {
            throw new InvalidPackage($problems);
        }

        $spec = self::map($spec);

        return new Document($kind, $id, self::V1 === $format ? self::fromV1($kind, $spec) : $spec, $path);
    }

    /**
     * The kind a document names: in format v1 the manifest is of kind Template, and there is no
     * kind Package.
     */
    private static function kind(string $name, bool $v1): ?DocumentKind
    {
        if ($v1) {
            return self::V1_MANIFEST_KIND === $name ? DocumentKind::Package : (DocumentKind::Package->value === $name ? null : DocumentKind::tryFrom($name));
        }

        return DocumentKind::tryFrom($name);
    }

    /**
     * The spec of a v1 document as v2 has it: a view's template is its source, and a manifest
     * requires format v2.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private static function fromV1(DocumentKind $kind, array $spec): array
    {
        if (DocumentKind::View === $kind && \array_key_exists('template', $spec) && !\array_key_exists('source', $spec)) {
            $renamed = [];
            foreach ($spec as $key => $value) {
                $renamed['template' === $key ? 'source' : $key] = $value;
            }

            return $renamed;
        }
        if (DocumentKind::Package === $kind && \is_array($spec['requires'] ?? null) && self::V1 === ($spec['requires']['format'] ?? null)) {
            $spec['requires']['format'] = Format::VERSION;
        }

        return $spec;
    }

    /**
     * A YAML map with string keys, as every spec is: a key written as a number ("1: x") is still a
     * name there.
     *
     * @param array<mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function map(array $map): array
    {
        $keyed = [];
        foreach ($map as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    public function encode(Document $document): string
    {
        $data = ['format' => Format::VERSION, 'kind' => $document->kind->value, 'id' => $document->id, 'spec' => $document->spec];

        // Deep enough that no map is written inline; multi-line strings (layouts) as literal blocks.
        return Yaml::dump($data, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_NULL_AS_TILDE);
    }
}
