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

    {{-- No contents column here, unlike a prose page: the margin beside an
         endpoint belongs to the call itself, and a list of four links to
         sections already on screen would be paying for it with the width the
         parameter table needs. --}}
    @include('lusen::partials.endpoint', ['endpoint' => $endpoint, 'spec' => $spec, 'standalone' => true])

    @include('lusen::partials.pager', ['pager' => $pager ?? []])

    <p class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500">
        <span>
            This page as
            <a href="{{ $markdownHref }}" class="inline-flex items-center gap-1.5 underline hover:text-slate-900 dark:hover:text-white">@include('lusen::partials.icon', ['name' => 'markdown'])Markdown</a>,
            or the whole API as
            <a href="{{ $links->openapi() }}" class="inline-flex items-center gap-1.5 underline hover:text-slate-900 dark:hover:text-white">@include('lusen::partials.icon', ['name' => 'openapi'])OpenAPI</a>.
        </span>

        {{-- Hidden until the script can actually copy. Hands over the Markdown
             twin rather than the rendered page, which is what a model can
             use. --}}
        <button type="button" data-lusen-copy-page="{{ $markdownHref }}" hidden
                class="inline-flex items-center gap-1.5 underline hover:text-slate-900 dark:hover:text-white">
            @include('lusen::partials.icon', ['name' => 'copy'])
            Copy for an LLM
        </button>

        @include('lusen::partials.ask-ai', [
            'askUrl' => $links->canonical(ltrim($markdownHref, '/')),
            'askSubject' => $endpoint->method->value.' '.$endpoint->path(),
        ])
    </p>

@endsection
