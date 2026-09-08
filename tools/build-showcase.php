<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Showcase builder
|--------------------------------------------------------------------------
|
| Builds docs/ by running the package's real emitters over a fictional API,
| so the showcase is exactly what `php artisan lusen:build` produces rather
| than a mockup that can drift from it.
|
| The front page of docs/ is about the package, and is one hand-written file
| (landing.html). The generated example lives under docs/example/, so the
| example keeps an index of its own.
|
|   php tools/build-showcase.php
|
| Also writes a single-page render (the runtime mode) to the path given by
| --preview, for previewing the whole API in one file.
|
*/

use Lusen\Collect\PageCollector;
use Lusen\Emit\BladeRenderer;
use Lusen\Emit\EmittedFile;
use Lusen\Emit\EmitterRegistry;
use Lusen\Ir\ApiSpec;
use Lusen\LusenServiceProvider;
use Lusen\Pages\PageSections;
use Lusen\Support\Links;
use Lusen\Support\Writer;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$out = $root.'/docs';
// Scheme and host only - the docs path comes from the output url below.
$origin = 'https://lusen.oda.al';

/** @var ApiSpec $spec */
$spec = require __DIR__.'/demo-spec.php';

// Authored prose, plus whichever standard pages DefaultPages fills in. The
// root is passed so each page records where it lives in the repository, which
// is what the edit link below needs.
$authored = (new PageCollector(__DIR__.'/demo-pages', $root))->collect();
// The page about Lusen itself sits last: it is for somebody evaluating the
// package, not somebody integrating with the fictional API.
$spec = $spec->withSections(PageSections::build($spec, $authored, ['Getting started', 'Guides', 'About Lusen']));

$app = Application::create();
$app['config']->set('lusen.seo.json_ld', true);

// The showcase turns the playground on, because a shop window that hides the
// feature is not showing the product. api.acme.example does not exist, so
// pressing Send here demonstrates the failure path rather than a call.
$app['config']->set('lusen.try_it', ['enabled' => true, 'methods' => ['GET'], 'persist_token' => 'session', 'credentials' => false]);
// The showcase is a fictional company's docs, so it demonstrates the lead
// surfaces the way a real one would use them: an announcement, a way back to
// the site, and one button worth pressing.
$app['config']->set('lusen.product', [
    'name' => 'Acme',
    'url' => 'https://github.com/fpeposhi/lusen',
    'banner' => [
        'text' => 'v2 is generally available.',
        'label' => 'See what changed',
        'url' => '/example/pages/versioning.html',
    ],
    'action' => [
        'label' => 'Get an API key',
        'url' => 'https://github.com/fpeposhi/lusen',
        'note' => 'Free while you are building.',
    ],
]);

$app->register(LusenServiceProvider::class);

// Every written page links to its own file in the example folder, which is
// both the feature demonstrated and the way into the example. Set after the
// provider has merged its defaults: the merge is a top-level array_merge, so
// a single `ui` key set before it would replace the whole `ui` array and
// silently drop the snippets and the assistant links.
$app['config']->set('lusen.ui.edit_url', 'https://github.com/fpeposhi/lusen/edit/main/{path}');

$renderer = new BladeRenderer($app['view']);

$registry = new EmitterRegistry(
    output: ['url' => '/example', 'emitters' => ['html', 'markdown', 'openapi', 'llms', 'sitemap', 'search', 'postman', 'discovery']],
    renderer: $renderer,
    canonicalOrigin: $origin,
);

$files = [];

foreach ($registry->enabled() as $emitter) {
    foreach ($emitter->emit($spec) as $file) {
        $files[] = new EmittedFile('example/'.$file->path, $file->contents, $file->contentType);

        // The conventional location too, at the root of the domain, pointing
        // at the example: the one guessable URL an agent tries first.
        if ($file->path === '.well-known/api-docs') {
            $files[] = $file;
        }
    }
}

// The front page is about the package rather than the API, and it is one
// hand-written file: the example already shows what the emitters produce, and
// a landing page rendered through the documentation layout would carry the
// fictional API's navigation. The counts are substituted so the page cannot
// drift from the spec beside it.
$files[] = EmittedFile::html('index.html', strtr((string) file_get_contents(__DIR__.'/landing.html'), [
    '{{endpoints}}' => (string) count($spec->endpoints()),
    '{{groups}}' => (string) count($spec->groups),
]));
$files[] = new EmittedFile('lusen-icon.svg', (string) file_get_contents($root.'/art/lusen-icon.svg'), 'image/svg+xml');
// The front page shows the example rather than describing it; the screenshot
// is the one in the README, so the two cannot show different things.
$files[] = new EmittedFile('screenshot-endpoint.png', (string) file_get_contents($root.'/art/screenshots/endpoint.png'), 'image/png');

// GitHub Pages runs Jekyll by default, which skips directories it does not
// recognise and would drop files beginning with an underscore.
$files[] = new EmittedFile('.nojekyll', '');

// The custom domain, emitted rather than dropped in by hand: this directory is
// build output, and a CNAME that only exists because somebody once created it
// is a custom domain that disappears the first time the output is cleaned.
$files[] = new EmittedFile('CNAME', "lusen.oda.al\n");

$result = (new Writer($out))->writeAll($files);

printf(
    "%d files written, %d unchanged (%s)\n",
    $result['written'],
    $result['skipped'],
    number_format($result['bytes'] / 1024, 1).' KB',
);

foreach ($files as $file) {
    printf("  %-38s %s\n", $file->path, number_format($file->bytes()).' B');
}

// The single-page runtime render, for a one-file preview of the whole API.
$previewIndex = array_search('--preview', $argv, true);

if ($previewIndex !== false && isset($argv[$previewIndex + 1])) {
    $preview = $renderer->render('lusen::index', [
        'spec' => $spec,
        'links' => new Links('/lusen'),
        'docsUrl' => '/lusen',
        'canonical' => $origin.'/lusen/',
        'description' => $spec->description,
    ]);

    file_put_contents($argv[$previewIndex + 1], $preview);
    printf("\npreview → %s (%s B)\n", $argv[$previewIndex + 1], number_format(strlen($preview)));
}

printf("\n%d endpoints across %d groups → %s\n", count($spec->endpoints()), count($spec->groups), $out);
