@extends('lusen::layout')

@section('content')

    {{-- Breadcrumbs are the page's only structural context when a reader
         arrives here straight from a search result, which is the common case
         for a per-endpoint page. --}}
    <nav aria-label="Breadcrumb" class="text-sm">
        <ol class="flex flex-wrap items-center gap-2 text-slate-500">
            <li><a href="{{ $links->index() }}" class="hover:text-slate-900 dark:hover:text-white">{{ $spec->title }}</a></li>
            @php($breadcrumbGroup = $spec->groupFor($endpoint))
            @if ($breadcrumbGroup)
                <li aria-hidden="true">/</li>
                <li><a href="{{ $links->group($breadcrumbGroup) }}" class="hover:text-slate-900 dark:hover:text-white">{{ $breadcrumbGroup->displayName() }}</a></li>
            @endif
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-slate-900 dark:text-white">{{ $endpoint->title() }}</li>
        </ol>
    </nav>

    <h1 class="mt-4 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $endpoint->title() }}</h1>

    <div class="xl:grid xl:grid-cols-[minmax(0,1fr)_13rem] xl:gap-10">
        <div class="min-w-0 xl:col-start-1 xl:row-start-1">
            @include('lusen::partials.endpoint', ['endpoint' => $endpoint, 'spec' => $spec, 'standalone' => true])

            @include('lusen::partials.pager', ['pager' => $pager ?? []])

            <p class="mt-8 text-sm text-slate-500">
                This page as
                <a href="{{ $markdownHref }}" class="underline hover:text-slate-900 dark:hover:text-white">Markdown</a>,
                or the whole API as
                <a href="{{ $links->openapi() }}" class="underline hover:text-slate-900 dark:hover:text-white">OpenAPI</a>.
            </p>
        </div>

        {{-- Placed by the grid rather than by document order, so the page
             still reads method, path, parameters, example - a contents list
             wedged between the title and the method line would be the first
             thing a model retrieving this page learned about it. Only rendered
             where there is a margin to put it in. --}}
        <div class="hidden xl:col-start-2 xl:row-start-1 xl:block xl:pt-6">
            @include('lusen::partials.rail', ['contents' => \Lusen\Support\Outline::forEndpoint($endpoint)])
        </div>
    </div>

@endsection
