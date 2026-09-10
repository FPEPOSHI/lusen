<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;

/**
 * Larastan analyses a package against a bare Testbench application, and that
 * application never registers `LusenServiceProvider` - so the `lusen::` view
 * namespace the provider declares does not exist while PHPStan runs. Larastan
 * resolves a `view-string` by asking `view()->exists()`, which meant every
 * `lusen::index` in `src/` analysed as a string that names no view.
 *
 * This is the same line the provider runs, so the check stops being a false
 * positive and starts being worth having: a view renamed under
 * `resources/views` without its call site updated is now a static-analysis
 * error rather than a 500 at render time.
 */
View::addNamespace('lusen', __DIR__.'/../resources/views');
