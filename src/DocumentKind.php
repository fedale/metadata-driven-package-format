<?php

declare(strict_types=1);

namespace Fedale\PackageFormat;

enum DocumentKind: string
{
    /** The manifest: the package's name, version and what it requires. */
    case Package = 'Package';
    /** An object and its fields. */
    case Object = 'Object';
    /** A composite form. */
    case Form = 'Form';
    /** A form layout or a grid view of an object. */
    case View = 'View';
    /** The texts of one language. */
    case Translations = 'Translations';
    /** Records of one object, written at install. */
    case Seed = 'Seed';
    /** A workflow, installed as a draft: its objects, and the workflows it calls, as ids. */
    case Workflow = 'Workflow';

    /**
     * The directory of the package its documents are written to; the manifest is at the root.
     */
    public function directory(): string
    {
        return match ($this) {
            self::Package => '',
            self::Object => 'objects',
            self::Form => 'forms',
            self::View => 'views',
            self::Translations => 'translations',
            self::Seed => 'seeds',
            self::Workflow => 'workflows',
        };
    }
}
