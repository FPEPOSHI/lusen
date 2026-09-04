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
$origin = 'https://fpeposhi.github.io';

/** @var ApiSpec $spec */
$spec = require __DIR__.'/demo-spec.php';

// Authored prose, plus whichever standard pages DefaultPages fills in.
$authored = (new PageCollector(__DIR__.'/demo-pages'))->collect();
$spec = $spec->withSections(PageSections::build($spec, $authored));

$app = Application::create();
$app['config']->set('lusen.seo.json_ld', true);
$app->register(LusenServiceProvider::class);

$renderer = new BladeRenderer($app['view']);

$registry = new EmitterRegistry(
    output: ['url' => '/lusen', 'emitters' => ['html', 'markdown', 'openapi', 'llms', 'sitemap', 'search', 'postman', 'discovery']],
    renderer: $renderer,
    canonicalOrigin: $origin,
);

$files = [];

foreach ($registry->enabled() as $emitter) {
    foreach ($emitter->emit($spec) as $file) {
        $files[] = $file;
    }
}

// GitHub Pages runs Jekyll by default, which skips directories it does not
// recognise and would drop files beginning with an underscore.
$files[] = new EmittedFile('.nojekyll', '');

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
