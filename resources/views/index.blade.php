@extends('lusen::layout')

@section('content')

    <header class="border-b border-slate-200 pb-8 dark:border-slate-800">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $spec->title }}</h1>

        @if ($spec->description)
            <p class="mt-3 max-w-2xl text-slate-600 dark:text-slate-400">{{ $spec->description }}</p>
        @endif

        @if ($spec->isVersioned())
            <p class="mt-3 max-w-2xl text-slate-600 dark:text-slate-400">
                This API serves {{ count($spec->versions) }} versions:
                @foreach ($spec->versions as $apiVersion)<code class="font-mono text-slate-900 dark:text-white">{{ $apiVersion->name }}</code> ({{ $apiVersion->status() }}){{ $loop->last ? '.' : ', ' }}@endforeach
                @if ($spec->currentVersion())
                    Write new integrations against <code class="font-mono text-slate-900 dark:text-white">{{ $spec->currentVersion()->name }}</code>.
                @endif
            </p>
        @endif

        <dl class="mt-6 flex flex-wrap gap-x-8 gap-y-2 text-sm">
            <div class="flex gap-2">
                <dt class="text-slate-500">Version</dt>
                <dd class="font-mono text-slate-900 dark:text-white">{{ $spec->version }}</dd>
            </div>
            @if ($spec->baseUrl)
                <div class="flex gap-2">
                    <dt class="text-slate-500">Base URL</dt>
                    <dd class="font-mono text-slate-900 dark:text-white">{{ $spec->baseUrl }}</dd>
                </div>
            @endif
            <div class="flex gap-2">
                <dt class="text-slate-500">Endpoints</dt>
                <dd class="font-mono text-slate-900 dark:text-white">{{ count($spec->endpoints()) }}</dd>
            </div>
        </dl>
    </header>

    {{-- Stated on the page, not only in <link rel="alternate">, because a
         human evaluating the API wants to know these exist too. --}}
    <section aria-labelledby="machine-readable-heading" class="mt-8 rounded-lg border border-slate-200 bg-slate-50 p-5 dark:border-slate-800 dark:bg-slate-900/40">
        <h2 id="machine-readable-heading" class="text-xs font-semibold uppercase tracking-wider text-slate-500">
            For machines
        </h2>

        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
            Every page here is also available in a form an agent or a crawler can read directly.
        </p>

        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
                <dt><a href="{{ $links->openapi() }}" class="font-mono text-sm text-indigo-600 underline dark:text-indigo-400">openapi.json</a></dt>
                <dd class="text-sm text-slate-600 dark:text-slate-400">OpenAPI 3.1, so generated clients get real JSON Schema.</dd>
            </div>
            <div>
                <dt><a href="{{ $links->llms() }}" class="font-mono text-sm text-indigo-600 underline dark:text-indigo-400">llms.txt</a></dt>
                <dd class="text-sm text-slate-600 dark:text-slate-400">A one-line-per-endpoint index for retrieval models.</dd>
            </div>
            <div>
                <dt><a href="{{ $links->llmsFull() }}" class="font-mono text-sm text-indigo-600 underline dark:text-indigo-400">llms-full.txt</a></dt>
                <dd class="text-sm text-slate-600 dark:text-slate-400">The entire API as Markdown in one file.</dd>
            </div>
            <div>
                <dt><a href="{{ $links->discovery() }}" class="font-mono text-sm text-indigo-600 underline dark:text-indigo-400">/.well-known/api-docs</a></dt>
                <dd class="text-sm text-slate-600 dark:text-slate-400">Discovery, from one guessable URL.</dd>
            </div>
        </dl>

        @php($askLinks = \Lusen\Support\AskAi::links(
            config('lusen.ui.ask_ai'),
            $links->canonical(ltrim($links->llmsFull(), '/')),
            'every endpoint',
            $spec->title,
        ))

        @if ($askLinks)
            {{-- Pointed at llms-full.txt rather than a page: someone asking
                 about the API as a whole wants the model to have the whole
                 thing, and that file exists so it can. --}}
            <p class="mt-4 flex flex-wrap gap-x-4 text-sm">
                @foreach ($askLinks as $askLabel => $askHref)
                    <a href="{{ $askHref }}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center gap-1.5 text-indigo-600 underline dark:text-indigo-400">@include('lusen::partials.icon', ['name' => 'ask'])Ask {{ $askLabel }} about this API</a>
                @endforeach
            </p>
        @endif

        <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">
            @if ($links->isStatic())
                {{-- Static files cannot content-negotiate, so the claim would
                     be false here. The extension swap works in both modes. --}}
                Every endpoint page has a Markdown twin: swap
                <code class="font-mono text-slate-900 dark:text-white">.html</code> for
                <code class="font-mono text-slate-900 dark:text-white">.md</code> on any of them.
            @else
                Any documentation URL also honours
                <code class="font-mono text-slate-900 dark:text-white">Accept: text/markdown</code>.
            @endif
        </p>
    </section>

    @foreach ($spec->sections as $section)
        <section id="{{ $section->slug() }}" class="scroll-mt-8 pt-12" aria-labelledby="{{ $section->slug() }}-heading">
            <h2 id="{{ $section->slug() }}-heading" class="text-xl font-semibold tracking-tight text-slate-900 dark:text-white">
                {{ $section->name }}
            </h2>

            {{-- Static output gives each page its own file, so the index
                 links to them. At runtime there is only one page, so the
                 prose is rendered inline under its own anchor. --}}
            @if ($links->isStatic())
                <ul class="mt-4 divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($section->pages as $sectionPage)
                        <li>
                            <a href="{{ $links->page($sectionPage) }}" class="block py-3 hover:bg-slate-50 dark:hover:bg-slate-900/40">
                                <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $sectionPage->title }}</span>
                                <span class="mt-0.5 block text-sm text-slate-600 dark:text-slate-400">{{ $sectionPage->summary() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                @foreach ($section->pages as $sectionPage)
                    <article id="page-{{ $sectionPage->slug() }}" class="scroll-mt-8 pt-8">
                        <h3 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white">{{ $sectionPage->title }}</h3>
                        <div class="lusen-prose mt-3">{!! \Lusen\Support\MarkdownDocument::render($sectionPage->markdown)->html !!}</div>
                    </article>
                @endforeach
            @endif
        </section>
    @endforeach

    @foreach ($spec->groups as $group)
        <section id="{{ $group->slug() }}" class="scroll-mt-8 pt-12" aria-labelledby="{{ $group->slug() }}-heading">
            {{-- The version belongs in the heading here, not above it: each
                 section is its own anchor and its own search result, and a
                 heading that needs the one above it to make sense is a heading
                 that arrives without its context. --}}
            <h2 id="{{ $group->slug() }}-heading" class="text-xl font-semibold tracking-tight text-slate-900 dark:text-white">
                {{ $group->displayName() }}
            </h2>

            @if ($group->description)
                <p class="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-400">{{ $group->description }}</p>
            @endif

            {{-- In static output each endpoint owns a page, so the index lists
                 them instead of repeating their content. Reproducing every
                 endpoint here would make the index duplicate content that
                 competes with the pages meant to rank for those operations.
                 At runtime there is only one page, so the full detail goes
                 inline. --}}
            @if ($links->isStatic())
                <ul class="mt-6 divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($group->endpoints as $endpoint)
                        <li>
                            <a href="{{ $links->endpoint($endpoint) }}"
                               class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3 hover:bg-slate-50 dark:hover:bg-slate-900/40">
                                @include('lusen::partials.method-badge', ['method' => $endpoint->method])
                                <code class="font-mono text-sm text-slate-900 dark:text-white">{{ $endpoint->path() }}</code>
                                <span class="text-sm text-slate-600 dark:text-slate-400">{{ $endpoint->summary }}</span>
                                @if ($endpoint->deprecated)
                                    <span class="text-xs font-medium text-rose-700 dark:text-rose-400">Deprecated</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="mt-6 space-y-10">
                    @foreach ($group->endpoints as $endpoint)
                        @include('lusen::partials.endpoint', ['endpoint' => $endpoint, 'spec' => $spec])
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach

@endsection
