<?php

declare(strict_types=1);

namespace Lusen\Support;

/**
 * Syntax highlighting done at build time.
 *
 * Deliberately not highlight.js or Prism. Those cost a CDN request the CSP
 * would have to allow, tens of kilobytes of JavaScript, and a flash of
 * unstyled code before they run - to colour two languages on a page that is
 * otherwise readable with JavaScript switched off. Emitting spans while the
 * page is generated costs the reader nothing at all.
 *
 * Scope is exactly what the docs emit: JSON bodies, shell commands and the
 * JavaScript snippet. Anything else is escaped and left alone rather than
 * mangled by a half-correct tokenizer.
 */
final class Highlighter
{
    public static function highlight(string $code, string $language): string
    {
        return match ($language) {
            'json' => self::json($code),
            'bash', 'sh', 'shell', 'curl' => self::shell($code),
            'javascript', 'js' => self::javascript($code),
            default => self::escape($code),
        };
    }

    /**
     * Keys, strings, numbers, booleans and null. Structural punctuation stays
     * unstyled so the shape of the document still reads at a glance.
     */
    public static function json(string $code): string
    {
        $pattern = '/("(?:\\\\.|[^"\\\\])*")(\s*:)?|((?<![\w.])-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b)|(\btrue\b|\bfalse\b|\bnull\b)/';

        $result = preg_replace_callback(
            $pattern,
            static function (array $match): string {
                // A string followed by a colon is a key.
                if (($match[1] ?? '') !== '') {
                    $isKey = ($match[2] ?? '') !== '';
                    $class = $isKey ? 'tok-key' : 'tok-str';

                    return '<span class="'.$class.'">'.self::escape($match[1]).'</span>'
                        .self::escape($match[2] ?? '');
                }

                if (($match[3] ?? '') !== '') {
                    return '<span class="tok-num">'.self::escape($match[3]).'</span>';
                }

                return '<span class="tok-lit">'.self::escape($match[4] ?? '').'</span>';
            },
            self::escapeOutsideMatches($code, $pattern),
        );

        return $result ?? self::escape($code);
    }

    /**
     * The command, its flags, quoted arguments and URLs.
     */
    public static function shell(string $code): string
    {
        $pattern = "/('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")|(^\\s*[a-zA-Z][\\w.-]*)|(\\s-{1,2}[A-Za-z][\\w-]*)|(\\\\\$)/m";

        $result = preg_replace_callback(
            $pattern,
            static function (array $match): string {
                if ($match[1] !== '') {
                    return '<span class="tok-str">'.self::escape($match[1]).'</span>';
                }

                if ($match[2] !== '') {
                    return '<span class="tok-cmd">'.self::escape($match[2]).'</span>';
                }

                if ($match[3] !== '') {
                    return '<span class="tok-flag">'.self::escape($match[3]).'</span>';
                }

                return '<span class="tok-punc">'.self::escape($match[4]).'</span>';
            },
            self::escapeOutsideMatches($code, $pattern),
        );

        return $result ?? self::escape($code);
    }

    /**
     * The fetch snippet: keywords, the calls, object keys and their values.
     *
     * Written for what `Snippets::javascript()` emits rather than for the
     * language - one page had a coloured cURL block above a plain JavaScript
     * one, which reads as a bug in the docs. A general JavaScript tokenizer
     * would be a much larger thing to get wrong.
     */
    public static function javascript(string $code): string
    {
        $pattern = '/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(\s*:)?'
            .'|\b(const|let|var|await|async|function|return|new|typeof)\b'
            .'|\b(true|false|null|undefined)\b'
            .'|((?<![\w.])-?\d+(?:\.\d+)?\b)'
            .'|\b([A-Za-z_$][\w$]*)(?=\s*\()'
            .'|\b([A-Za-z_$][\w$]*)(?=\s*:)/';

        $result = preg_replace_callback(
            $pattern,
            static function (array $match): string {
                // A string followed by a colon is a key, exactly as in JSON.
                if (($match[1] ?? '') !== '') {
                    $class = ($match[2] ?? '') !== '' ? 'tok-key' : 'tok-str';

                    return '<span class="'.$class.'">'.self::escape($match[1]).'</span>'
                        .self::escape($match[2] ?? '');
                }

                // Keyword, literal, number, the name being called, a bare key.
                $classes = [3 => 'tok-lit', 4 => 'tok-lit', 5 => 'tok-num', 6 => 'tok-cmd', 7 => 'tok-key'];

                foreach ($classes as $group => $class) {
                    if (($match[$group] ?? '') !== '') {
                        return '<span class="'.$class.'">'.self::escape($match[$group]).'</span>';
                    }
                }

                return self::escape($match[0]);
            },
            self::escapeOutsideMatches($code, $pattern),
        );

        return $result ?? self::escape($code);
    }

    /**
     * Escapes everything the tokenizer will not touch, so the callback can
     * escape its own matches and the two never double-escape each other.
     */
    private static function escapeOutsideMatches(string $code, string $pattern): string
    {
        $out = '';
        $offset = 0;

        while (preg_match($pattern, $code, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $length = strlen($match[0][0]);

            $out .= self::escape(substr($code, $offset, $start - $offset));
            $out .= $match[0][0];

            $offset = $start + max(1, $length);
        }

        return $out.self::escape(substr($code, $offset));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: false);
    }
}
