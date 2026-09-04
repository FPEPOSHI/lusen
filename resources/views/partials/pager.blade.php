{{-- Previous/next across the whole documentation, prose and reference alike,
     so the site can be read straight through rather than only searched. --}}
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
