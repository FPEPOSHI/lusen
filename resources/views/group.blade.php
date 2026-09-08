@extends('lusen::layout')

@section('content')

    {{-- The page for a bare noun - "orders", not "create an order" - which is
         what a search engine or an assistant is asked before it knows an
         operation's name. It lists the operations rather than repeating
         them: each keeps its own page, and a page reproducing them here would
         compete with them for their own queries. --}}
    <nav aria-label="Breadcrumb" class="text-sm">
        <ol class="flex flex-wrap items-center gap-2 text-slate-500">
            <li><a href="{{ $links->index() }}" class="hover:text-slate-900 dark:hover:text-white">{{ $spec->title }}</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-slate-900 dark:text-white">{{ $group->displayName() }}</li>
        </ol>
    </nav>

    <h1 class="mt-4 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $group->displayName() }}</h1>

    @if ($group->description)
        <p class="mt-3 max-w-2xl text-slate-600 dark:text-slate-400">{{ $group->description }}</p>
    @endif

    {{-- Repeated here rather than linked: a page has to stand alone, and
         these are the things a reader needs before the first call. --}}
    <dl class="mt-6 flex flex-wrap gap-x-8 gap-y-2 text-sm">
        @if ($group->version)
            <div class="flex gap-2">
                <dt class="text-slate-500">API version</dt>
                <dd class="font-mono text-slate-900 dark:text-white">{{ $spec->apiVersion($group->version)?->label() ?? $group->version }}</dd>
            </div>
        @endif
        @if ($spec->baseUrl)
            <div class="flex gap-2">
                <dt class="text-slate-500">Base URL</dt>
                <dd class="font-mono text-slate-900 dark:text-white">{{ $spec->baseUrl }}</dd>
            </div>
        @endif
        <div class="flex gap-2">
            <dt class="text-slate-500">Operations</dt>
            <dd class="font-mono text-slate-900 dark:text-white">{{ count($group->endpoints) }}</dd>
        </div>
    </dl>

    <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ $group->authenticationSummary() }}</p>

    {{-- The same grid as a prose page: the list on the left, and beside it the
         ways to take this page somewhere else. --}}
    <div class="mt-6 xl:grid xl:grid-cols-[minmax(0,1fr)_13rem] xl:gap-10">
        <div class="mb-8 xl:col-start-2 xl:row-start-1 xl:mb-0">
            @include('lusen::partials.rail', ['contents' => [], 'askSubject' => 'the '.$group->name.' operations'])
        </div>

        <div class="min-w-0 xl:col-start-1 xl:row-start-1">
            <section aria-labelledby="{{ $group->slug() }}-operations">
                <h2 id="{{ $group->slug() }}-operations" class="text-xl font-semibold tracking-tight text-slate-900 dark:text-white">Operations</h2>

                <ul class="mt-4 divide-y divide-slate-200 dark:divide-slate-800">
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
            </section>

            @include('lusen::partials.pager', ['pager' => $pager ?? []])
        </div>
    </div>

@endsection
