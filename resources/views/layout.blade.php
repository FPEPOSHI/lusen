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
      - Every screen size reaches every page. The navigation is rendered once
        and moved by CSS - a column beside the content on wide screens, the
        end of the document on narrow ones, where the bar at the top of the
        page links to it.
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

{{-- Narrow screens have no room for a column of navigation, so it sits at the
     end of the document and this bar points at it. The control is a link to
     that anchor, which works with no JavaScript at all; the script upgrades it
     to a toggle that opens the same navigation as a panel instead of sending
     the reader to the bottom of the page. --}}
<div class="sticky top-0 z-30 flex items-center justify-between gap-4 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur sm:px-6 lg:hidden dark:border-slate-800 dark:bg-slate-950/90">
    <a href="{{ $links->index() }}" class="min-w-0 truncate text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
        {{ $spec->title }}
    </a>

    <a href="#navigation" data-lusen-menu aria-controls="navigation"
       class="shrink-0 rounded-md border border-slate-200 px-2.5 py-1 text-sm font-medium text-slate-600 hover:border-indigo-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-400 dark:hover:text-white">
        Menu
    </a>
</div>

{{-- 96rem rather than Tailwind's 7xl: an endpoint page is three things side
     by side - navigation, reference, examples - and at 80rem the parameter
     table paid for it, wrapping a type like "string, one of pending, paid,
     shipped, refunded" over three lines. Nothing changes below 1280px, where
     the viewport is the constraint. --}}
<div class="mx-auto flex max-w-[96rem] flex-col gap-8 px-4 sm:px-6 lg:flex-row lg:px-8">

    {{-- `order-last` moves it below the content on narrow screens without
         moving it in the document, so the reading order stays what it is on
         a wide one and the skip link still does its job. --}}
    {{-- Wider where there is room: "Register a webhook endpo…" is a truncation
         that costs a reader the one word telling them which endpoint it is. --}}
    <aside class="lusen-nav order-last w-full shrink-0 border-slate-200 lg:order-first lg:w-64 lg:border-r xl:w-72 dark:border-slate-800">
        {{-- Sticky on wide screens: the sidebar is the only way between pages,
             and a long endpoint body would otherwise scroll it out of reach.
             It scrolls independently once it outgrows the viewport. --}}
        <div class="border-t border-slate-200 py-10 lg:sticky lg:top-0 lg:max-h-screen lg:overflow-y-auto lg:border-t-0 lg:pr-4 dark:border-slate-800">
            @include('lusen::partials.nav')
        </div>
    </aside>

    <main id="content" class="min-w-0 flex-1 py-10">
        {{ $slot ?? '' }}
        @yield('content')
    </main>

</div>

<footer class="mx-auto flex max-w-[96rem] flex-wrap items-center justify-between gap-4 px-4 py-10 text-xs text-slate-500 sm:px-6 lg:px-8">
    <p>
        Generated by <a href="https://github.com/fpeposhi/lusen" class="underline hover:text-slate-900 dark:hover:text-white">Lusen</a>.
        Machine-readable:
        <a href="{{ $links->openapi() }}" class="underline hover:text-slate-900 dark:hover:text-white">OpenAPI</a>,
        <a href="{{ $links->llms() }}" class="underline hover:text-slate-900 dark:hover:text-white">llms.txt</a>.
    </p>

    @if (config('lusen.ui.dark_mode', true))
        {{-- Down here rather than in the navigation: it is set once and then
             never touched again, and it was taking the place at the top of the
             sidebar that belongs to what the reader came for.

             Hidden until JavaScript reveals it: a theme button that cannot
             change the theme is worse than none. --}}
        <button type="button" data-lusen-theme hidden
                class="rounded-md border border-slate-200 px-2.5 py-1 text-xs text-slate-500 hover:border-indigo-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-400 dark:hover:text-white">
            <span data-lusen-theme-label>Theme</span>
        </button>
    @endif
</footer>

@if ($jsHref ?? null)
    {{-- Static output shares one cached file across every page. The runtime
         renderer still inlines: there is a single page there, and no second
         request to save. --}}
    <script src="{{ $jsHref }}" defer></script>
@else
    <script>{!! \Lusen\Support\Assets::js() !!}</script>
@endif
<script>window.lusenSearchIndex = @json($links->searchIndex());</script>
@if (\Lusen\Support\TryIt::configured(config('lusen.try_it')))
    <script>window.lusenTryIt = @json(\Lusen\Support\TryIt::options(config('lusen.try_it'), $spec->baseUrl), JSON_HEX_TAG | JSON_HEX_AMP);</script>
@endif

</body>
</html>
