@extends('lusen::layout')

@section('content')

    <nav aria-label="Breadcrumb" class="text-sm">
        <ol class="flex flex-wrap items-center gap-2 text-slate-500">
            <li><a href="{{ $links->index() }}" class="hover:text-slate-900 dark:hover:text-white">{{ $spec->title }}</a></li>
            @if ($page->section)
                <li aria-hidden="true">/</li>
                <li>{{ $page->section }}</li>
            @endif
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-slate-900 dark:text-white">{{ $page->title }}</li>
        </ol>
    </nav>

    <h1 class="mt-4 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $page->title }}</h1>

    {{-- The contents comes first in the document and second in the layout: on a
         narrow screen it belongs under the title, where a reader looks for it,
         and on a wide one it belongs in the margin the prose column leaves
         empty. Grid placement gets both from one element rather than rendering
         it twice and hiding one. --}}
    <div class="mt-6 xl:grid xl:grid-cols-[minmax(0,1fr)_13rem] xl:gap-10">
        <div class="mb-8 xl:col-start-2 xl:row-start-1 xl:mb-0">
            @include('lusen::partials.rail', ['contents' => $contents])
        </div>

        <div class="min-w-0 xl:col-start-1 xl:row-start-1">
            {{-- Rendered from the page's Markdown. Prose styling lives here
                 rather than in the Markdown itself so authored and generated
                 pages look the same. --}}
            <div class="lusen-prose">{!! $body !!}</div>

            {{-- The page that explains the credentials is where you set them.
                 Keyed on the page rather than on a marker in its Markdown, so
                 an authored authentication page gets the section too and the
                 .md twin stays free of markup it cannot render. --}}
            @if ($page->id === 'authentication')
                @include('lusen::partials.auth-setup')
            @endif

            @include('lusen::partials.pager', ['pager' => $pager])
        </div>
    </div>

@endsection
