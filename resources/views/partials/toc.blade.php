{{-- On-page contents. Only rendered when the page has enough headings to be
     worth navigating; two entries is a list, not a table of contents. --}}
@if (count($contents ?? []) > 1)
    <nav aria-labelledby="toc-heading">
        <h2 id="toc-heading" class="text-xs font-semibold uppercase tracking-wider text-slate-500">On this page</h2>
        <ul class="mt-3 space-y-1 border-l border-slate-200 dark:border-slate-800">
            @foreach ($contents as $heading)
                <li>
                    <a href="#{{ $heading['id'] }}"
                       class="-ml-px block border-l border-transparent py-1 text-sm text-slate-600 hover:border-indigo-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white {{ $heading['level'] === 3 ? 'pl-6' : 'pl-3' }}">
                        {{ $heading['text'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
