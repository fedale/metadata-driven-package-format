<?php

declare(strict_types=1);

namespace Fedale\PackageFormat;

/**
 * A text the format writes for a person, which an application may show in its own language.
 *
 * The English text is declared where the text is raised, its parameters as `%name%`
 * placeholders; casting to string gives it, filled in. The key names it for a translation: an
 * application that translates looks it up, with the parameters, and falls back to the English.
 * The key and the text are literal strings at every `new Text(...)`, so that an application can
 * read them from these sources to check its translations.
 */
final readonly class Text implements \Stringable
{
    /**
     * @param array<string, string|int|Text> $parameters by placeholder name, without the percent signs
     */
    public function __construct(
        public string $key,
        public string $text,
        public array $parameters = [],
    ) {
    }

    public function __toString(): string
    {
        $placeholders = [];
        foreach ($this->parameters as $name => $value) {
            $placeholders['%'.$name.'%'] = (string) $value;
        }

        return strtr($this->text, $placeholders);
    }
}
