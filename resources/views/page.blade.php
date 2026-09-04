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

    @include('lusen::partials.toc', ['contents' => $contents])

    {{-- Rendered from the page's Markdown. Prose styling lives here rather
         than in the Markdown itself so authored and generated pages look the
         same. --}}
    <div class="lusen-prose">{!! $body !!}</div>

    @include('lusen::partials.pager', ['pager' => $pager])

@endsection
