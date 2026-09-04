<?php

declare(strict_types=1);

namespace Lusen\Support;

/**
 * Serves the package's compiled CSS.
 *
 * dist/lusen.css is committed to the repository so installing Lusen never
 * requires Node. It is inlined into the page rather than linked: the docs are
 * one stylesheet under a few kilobytes gzipped, and inlining removes a
 * round-trip from first paint, which is the whole first-paint budget on a
 * documentation page.
 *
 * If you change classes in resources/views, run `npm run build` and commit
 * the result in the same change.
 */
final class Assets
{
    private static ?string $cached = null;

    private static ?string $cachedJs = null;

    public static function css(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $path = self::distPath();

        $css = is_file($path) ? file_get_contents($path) : false;

        return self::$cached = $css === false ? self::fallback() : $css;
    }

    /**
     * The progressive-enhancement script: copy buttons and search.
     *
     * Inlined rather than linked because it is under 4 KB and nothing on the
     * page waits for it - a separate request would cost more than it saves.
     */
    public static function js(): string
    {
        if (self::$cachedJs !== null) {
            return self::$cachedJs;
        }

        $js = is_file(self::jsPath()) ? file_get_contents(self::jsPath()) : false;

        return self::$cachedJs = $js === false ? '' : $js;
    }

    public static function distPath(): string
    {
        return dirname(__DIR__, 2).'/dist/lusen.css';
    }

    public static function jsPath(): string
    {
        return dirname(__DIR__, 2).'/resources/js/lusen.js';
    }

    public static function isBuilt(): bool
    {
        return is_file(self::distPath());
    }

    /**
     * Only reached when dist/lusen.css is missing - a source checkout that has
     * not run `npm run build`. Enough styling to keep the page legible rather
     * than a wall of unstyled text.
     */
    private static function fallback(): string
    {
        return <<<'CSS'
        :root{color-scheme:light dark}
        *,::before,::after{box-sizing:border-box}
        body{margin:0;font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#1e293b;background:#fff}
        @media (prefers-color-scheme:dark){body{color:#e2e8f0;background:#020617}}
        main{max-width:52rem;margin:0 auto;padding:2rem 1rem}
        h1,h2,h3{line-height:1.25;font-weight:650}
        code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.875em}
        pre{overflow-x:auto;padding:1rem;border-radius:.5rem;background:#0f172a;color:#e2e8f0}
        table{width:100%;border-collapse:collapse;margin:1rem 0}
        th,td{text-align:left;padding:.5rem;border-bottom:1px solid #e2e8f0;vertical-align:top}
        a{color:#4f46e5}
        aside{display:none}
        CSS;
    }
}
