<?php

declare(strict_types=1);

namespace Fedale\PackageFormat\Tests;

use Fedale\PackageFormat\Problem;
use Fedale\PackageFormat\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testAProblemKeepsItsEnglishAndItsText(): void
    {
        $text = new Text('archive_invalid', 'The archive is invalid: %why%', ['why' => new Text('archive_entry_twice', '"%path%" is in it twice.', ['path' => 'objects/product.yaml'])]);
        $problem = new Problem('', '', $text);

        self::assertSame('The archive is invalid: "objects/product.yaml" is in it twice.', $problem->message);
        self::assertSame($text, $problem->text);
        self::assertNull((new Problem('objects/product.yaml', 'spec', 'Invalid.'))->text);
    }

    public function testEveryTextOfTheSourcesHasALiteralKeyAndOneEnglish(): void
    {
        $texts = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $tokens = array_values(array_filter(
                \PhpToken::tokenize((string) file_get_contents($file->getPathname())),
                static fn (\PhpToken $token): bool => !$token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
            ));
            foreach ($tokens as $i => $token) {
                if (!$token->is(\T_NEW) || 'Text' !== ($tokens[$i + 1]->text ?? '')) {
                    continue;
                }
                $where = \sprintf('%s:%d', $file->getFilename(), $token->line);
                [, $open, $key, $comma, $text] = \array_slice($tokens, $i + 1, 5) + array_fill(0, 5, null);
                self::assertTrue('(' === $open?->text && ',' === $comma?->text && self::isLiteral($key) && self::isLiteral($text), $where.': the key and the English must be literal strings.');
                \assert(null !== $key && null !== $text);
                self::assertTrue(!isset($texts[$key->text]) || $texts[$key->text] === $text->text, $where.': '.$key->text.' has another English elsewhere.');
                $texts[$key->text] = $text->text;
            }
        }

        self::assertGreaterThan(50, \count($texts));
    }

    private static function isLiteral(?\PhpToken $token): bool
    {
        return null !== $token && $token->is(\T_CONSTANT_ENCAPSED_STRING) && str_starts_with($token->text, "'");
    }
}
