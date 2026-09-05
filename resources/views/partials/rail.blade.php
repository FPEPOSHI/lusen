{{--
    The narrow column beside the content: where you are in this page, and how
    to take this page somewhere else.

    Both halves are optional and the column renders whichever it has. The
    actions are the point of the second half - a reader who wants to paste this
    page into a model should not have to know that swapping .html for .md is a
    thing Lusen does.
--}}
@php($contents = $contents ?? [])
@php($editHref = \Lusen\Support\EditLink::for($page ?? null, config('lusen.ui.edit_url')))

<div class="xl:sticky xl:top-10 xl:max-h-[calc(100vh-5rem)] xl:overflow-y-auto">
    @include('lusen::partials.toc', ['contents' => $contents])

    @if ($markdownHref ?? null)
        <div class="{{ count($contents) > 1 ? 'mt-8' : '' }}">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">This page</h2>

            {{-- One row until there is a margin to stack it in: below the
                 rail breakpoint this sits between the title and the first
                 paragraph, and three stacked links there push the page's
                 opening sentence down for no reason. --}}
            <ul class="mt-3 flex flex-wrap gap-x-5 text-sm xl:block xl:space-y-1">
                <li>
                    <a href="{{ $markdownHref }}" class="block py-1 text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                        Markdown
                    </a>
                </li>
                <li>
                    {{-- Copies the Markdown twin rather than the rendered page:
                         hidden until the script can actually do it. --}}
                    <button type="button" data-lusen-copy-page="{{ $markdownHref }}" hidden
                            class="block py-1 text-left text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                        Copy for an LLM
                    </button>
                </li>
                <li>
                    <a href="{{ $links->openapi() }}" class="block py-1 text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                        OpenAPI
                    </a>
                </li>
                @if ($editHref)
                    {{-- Only ever shown for a page somebody wrote: a derived
                         page has no file behind it to open. --}}
                    <li>
                        <a href="{{ $editHref }}" class="block py-1 text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                            Edit this page
                        </a>
                    </li>
                @endif
            </ul>
        </div>
    @endif
</div>
