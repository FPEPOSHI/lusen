{{--
    Base shell for every documentation page.

    Rules this file exists to enforce:
      - The page is complete and readable with JavaScript disabled. Crawlers
        and retrieval models get the same bytes a browser does.
      - Every page carries a canonical URL, a real meta description and
        JSON-LD. These are the difference between being indexed and being
        cited.
      - Theme is set by a `prefers-color-scheme`-aware class on <html>, with
        no flash-of-wrong-theme script, because no JS is allowed to be
        load-bearing here.
--}}
<!doctype html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? $spec->title }}</title>
    <meta name="description" content="{{ $description ?? \Lusen\Support\Str::summarise($spec->description ?? $spec->title) }}">

    @if ($canonical ?? null)
        <link rel="canonical" href="{{ $canonical }}">
    @endif

    @if (config('lusen.seo.noindex'))
        <meta name="robots" content="noindex, nofollow">
    @else
        <meta name="robots" content="index, follow">
    @endif

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title ?? $spec->title }}">
    <meta property="og:description" content="{{ $description ?? \Lusen\Support\Str::summarise($spec->description ?? $spec->title) }}">
    @if (config('lusen.seo.og_image'))
        <meta property="og:image" content="{{ config('lusen.seo.og_image') }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">

    @if (config('lusen.ui.favicon'))
        <link rel="icon" href="{{ config('lusen.ui.favicon') }}">
    @endif

    {{-- Machine-readable siblings of this page, advertised in the head so an
         agent that fetched the HTML can find them without another request. --}}
    <link rel="alternate" type="application/json" href="{{ $links->openapi() }}" title="OpenAPI 3.1">
    <link rel="alternate" type="text/markdown" href="{{ $markdownHref ?? $links->llmsFull() }}" title="Markdown">
    <link rel="alternate" type="application/json" href="{{ $links->discovery() }}" title="Discovery">

    @if (config('lusen.seo.json_ld'))
        <script type="application/ld+json">{!! $jsonLd ?? \Lusen\Support\JsonLd::forSpec($spec, $docsUrl) !!}</script>
    @endif

    @if (config('lusen.ui.dark_mode', true))
        {{-- Applied before first paint so a chosen theme does not flash the
             other one. Without JavaScript nothing is stamped and the page
             follows prefers-color-scheme, which is the correct default. --}}
        <script>try{var t=localStorage.getItem('lusen-theme');if(t)document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
    @endif

    @if ($cssHref ?? null)
        <link rel="stylesheet" href="{{ $cssHref }}">
    @else
        <style>{!! \Lusen\Support\Assets::css() !!}</style>
    @endif
</head>
<body class="bg-white text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">

<a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-indigo-600 focus:px-3 focus:py-2 focus:text-white">
    Skip to content
</a>

<div class="mx-auto flex max-w-7xl gap-8 px-4 sm:px-6 lg:px-8">

    <aside class="hidden w-64 shrink-0 border-r border-slate-200 lg:block dark:border-slate-800">
        {{-- Sticky: the sidebar is the only way between pages, and a long
             endpoint body would otherwise scroll it out of reach. It scrolls
             independently once it outgrows the viewport. --}}
        <div class="sticky top-0 max-h-screen overflow-y-auto py-10 pr-4">
        <nav aria-label="Documentation">
            @include('lusen::partials.search')

            @if (config('lusen.ui.dark_mode', true))
                {{-- Hidden until JavaScript reveals it: a theme button that
                     cannot change the theme is worse than none. --}}
                <button type="button" data-lusen-theme hidden
                        class="mb-4 w-full rounded-md border border-slate-200 px-3 py-1.5 text-left text-sm text-slate-600 hover:border-indigo-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-400 dark:hover:text-white">
                    <span data-lusen-theme-label>Theme</span>
                </button>
            @endif

            <a href="{{ $links->index() }}" class="block text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
                @if (config('lusen.ui.logo'))
                    <img src="{{ config('lusen.ui.logo') }}" alt="{{ $spec->title }}" class="mb-2 h-8 w-auto">
                @endif
                {{ $spec->title }}
            </a>
            <p class="mt-1 font-mono text-xs text-slate-500">v{{ $spec->version }}</p>

            <ul class="mt-8 space-y-6">
                @foreach ($spec->sections as $section)
                    <li>
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $section->name }}</span>
                        <ul class="mt-2 space-y-1 border-l border-slate-200 dark:border-slate-800">
                            @foreach ($section->pages as $sectionPage)
                                <li>
                                    <a href="{{ $links->page($sectionPage) }}"
                                       @if (($current ?? null) === 'page:'.$sectionPage->id) aria-current="page" @endif
                                       class="-ml-px block border-l py-1 pl-3 text-sm hover:border-indigo-500 hover:text-slate-900 dark:hover:text-white {{ ($current ?? null) === 'page:'.$sectionPage->id ? 'border-indigo-500 font-medium text-slate-900 dark:text-white' : 'border-transparent text-slate-600 dark:text-slate-400' }}">
                                        {{ $sectionPage->title }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach

                {{-- A version heading rather than "(v2)" on twenty group
                     labels: the sidebar is scanned vertically, and one heading
                     over eight groups reads faster than eight suffixes.
                     Groups arrive newest version first, so this only has to
                     notice the changes. --}}
                @php($sidebarVersion = false)

                @foreach ($spec->groups as $group)
                    @if ($spec->isVersioned() && $sidebarVersion !== $group->version)
                        @php($sidebarVersion = $group->version)
                        <li class="border-t border-slate-200 pt-4 dark:border-slate-800">
                            <span class="font-mono text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">
                                {{ $group->version === null ? 'Any version' : ($spec->apiVersion($group->version)?->label() ?? $group->version) }}
                            </span>
                        </li>
                    @endif

                    <li>
                        <a href="{{ $links->group($group) }}" class="text-xs font-semibold uppercase tracking-wider text-slate-500 hover:text-slate-900 dark:hover:text-white">
                            {{ $group->name }}
                        </a>
                        <ul class="mt-2 space-y-1 border-l border-slate-200 dark:border-slate-800">
                            @foreach ($group->endpoints as $endpoint)
                                <li>
                                    <a href="{{ $links->endpoint($endpoint) }}"
                                       @if (($current ?? null) === 'endpoint:'.$endpoint->id) aria-current="page" @endif
                                       class="-ml-px flex items-center gap-2 border-l py-1 pl-3 text-sm hover:border-indigo-500 hover:text-slate-900 dark:hover:text-white {{ ($current ?? null) === 'endpoint:'.$endpoint->id ? 'border-indigo-500 font-medium text-slate-900 dark:text-white' : 'border-transparent text-slate-600 dark:text-slate-400' }}">
                                        @include('lusen::partials.method-badge', ['method' => $endpoint->method, 'compact' => true])
                                        <span class="truncate">{{ $endpoint->summary ?? $endpoint->path() }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </nav>
        </div>
    </aside>

    <main id="content" class="min-w-0 flex-1 py-10">
        {{ $slot ?? '' }}
        @yield('content')
    </main>

</div>

<footer class="mx-auto max-w-7xl px-4 py-10 text-xs text-slate-500 sm:px-6 lg:px-8">
    <p>
        Generated by <a href="https://github.com/fpeposhi/lusen" class="underline hover:text-slate-900 dark:hover:text-white">Lusen</a>.
        Machine-readable:
        <a href="{{ $links->openapi() }}" class="underline hover:text-slate-900 dark:hover:text-white">OpenAPI</a>,
        <a href="{{ $links->llms() }}" class="underline hover:text-slate-900 dark:hover:text-white">llms.txt</a>.
    </p>
</footer>

<script>{!! \Lusen\Support\Assets::js() !!}</script>
<script>window.lusenSearchIndex = @json($links->searchIndex());</script>

</body>
</html>
