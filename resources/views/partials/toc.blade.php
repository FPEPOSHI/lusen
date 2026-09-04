{{-- On-page contents. Only rendered when the page has enough headings to be
     worth navigating; two entries is a list, not a table of contents. --}}
@if (count($contents ?? []) > 1)
    <nav aria-labelledby="toc-heading" class="mb-8 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
        <h2 id="toc-heading" class="text-xs font-semibold uppercase tracking-wider text-slate-500">On this page</h2>
        <ul class="mt-2 space-y-1">
            @foreach ($contents as $heading)
                <li class="{{ $heading['level'] === 3 ? 'pl-4' : '' }}">
                    <a href="#{{ $heading['id'] }}" class="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                        {{ $heading['text'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
