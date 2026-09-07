{{-- Previous/next across the whole documentation, prose and reference alike,
     so the site can be read straight through rather than only searched.

     The call to action sits underneath rather than between them: somebody who
     has read to the bottom of a page is the most likely reader on the site to
     want an account, and this is the one place they are not mid-sentence. --}}
@if (($pager['previous'] ?? null) || ($pager['next'] ?? null))
    <nav aria-label="Pagination" class="mt-12 flex flex-wrap gap-4 border-t border-slate-200 pt-6 dark:border-slate-800">
        @if ($pager['previous'] ?? null)
            <a href="{{ $pager['previous']['href'] }}" rel="prev" class="group flex-1 rounded-lg border border-slate-200 p-4 hover:border-indigo-500 dark:border-slate-800">
                <span class="text-xs uppercase tracking-wider text-slate-500">Previous</span>
                <span class="mt-1 block text-sm font-medium text-slate-900 dark:text-white">{{ $pager['previous']['title'] }}</span>
            </a>
        @endif

        @if ($pager['next'] ?? null)
            <a href="{{ $pager['next']['href'] }}" rel="next" class="group flex-1 rounded-lg border border-slate-200 p-4 text-right hover:border-indigo-500 dark:border-slate-800">
                <span class="text-xs uppercase tracking-wider text-slate-500">Next</span>
                <span class="mt-1 block text-sm font-medium text-slate-900 dark:text-white">{{ $pager['next']['title'] }}</span>
            </a>
        @endif
    </nav>
@endif

@php($pagerAction = \Lusen\Support\Product::action(config('lusen.product')))

@if ($pagerAction)
    {{-- The note is the sentence and the button is the label; printing the
         label above a button that already says it is the same words twice. --}}
    <div class="mt-6 flex flex-wrap items-center justify-end gap-4 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
        @if ($pagerAction['note'])
            <p class="min-w-0 flex-1 text-sm text-slate-600 dark:text-slate-400">{{ $pagerAction['note'] }}</p>
        @endif

        @include('lusen::partials.action', [
            'actionClass' => 'shrink-0 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500',
            'actionNote' => false,
        ])
    </div>
@endif
