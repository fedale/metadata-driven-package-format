<?php

declare(strict_types=1);

namespace Fedale\PackageFormat;

/**
 * Something wrong in a package, located by its document and, inside it, by a path of keys.
 *
 * $message is the English text. $text is the same with its key and parameters, for an
 * application that shows it in another language; it is null for a text that has no key (the
 * schema validator's).
 */
final readonly class Problem implements \Stringable
{
    public string $message;

    public ?Text $text;

    public function __construct(
        /** The document, e.g. "objects/product.yaml"; empty for the package as a whole. */
        public string $path,
        /** Inside the document, e.g. "spec.fields.2.settings"; empty for the document. */
        public string $at,
        string|Text $message,
    ) {
        $this->message = (string) $message;
        $this->text = $message instanceof Text ? $message : null;
    }

    public function __toString(): string
    {
        return implode(': ', array_filter([$this->path, $this->at, $this->message], static fn (string $part): bool => '' !== $part));
    }
}
