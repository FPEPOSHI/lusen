{{--
    The whole navigation: identity, search, prose sections, endpoint groups.

    Rendered exactly once per page and moved by CSS - a left column on wide
    screens, the end of the document on narrow ones. Rendering it twice would
    duplicate thirty links in every page's bytes and give two elements the same
    ids, and a reader on a phone would still be looking at whichever copy the
    script happened to find first.
--}}
<nav id="navigation" aria-label="Documentation">
    {{-- The API's name leads the sidebar. It is the one thing on the page that
         says which documentation this is, so it belongs above the controls
         rather than under them. --}}
    <a href="{{ $links->index() }}" class="block text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
        @if (config('lusen.ui.logo'))
            <img src="{{ config('lusen.ui.logo') }}" alt="{{ $spec->title }}" class="mb-2 h-8 w-auto">
        @endif
        {{ $spec->title }}
    </a>
    <p class="mt-1 mb-4 font-mono text-xs text-slate-500">v{{ $spec->version }}</p>

    @include('lusen::partials.search')

    @if (config('lusen.ui.dark_mode', true))
        {{-- Hidden until JavaScript reveals it: a theme button that cannot
             change the theme is worse than none. --}}
        <button type="button" data-lusen-theme hidden
                class="mb-4 w-full rounded-md border border-slate-200 px-3 py-1.5 text-left text-sm text-slate-600 hover:border-indigo-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-400 dark:hover:text-white">
            <span data-lusen-theme-label>Theme</span>
        </button>
    @endif

    @if ($spec->servers && $spec->baseUrl)
        {{-- An API with a sandbox is two base URLs, and a reader who copies an
             example against the wrong one gets a confusing 401 rather than an
             obvious mistake. Switching rewrites the host in every example on
             the page, so what is copied is what was chosen.

             Hidden until the script can actually rewrite anything; without it
             every example shows the default base URL, which is correct. --}}
        <label class="sr-only" for="lusen-server">Base URL</label>
        <select id="lusen-server" data-lusen-server hidden
                class="mb-4 w-full rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-600 hover:border-indigo-500 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
            <option value="{{ rtrim($spec->baseUrl, '/') }}">{{ parse_url($spec->baseUrl, PHP_URL_HOST) ?: $spec->baseUrl }}</option>
            @foreach ($spec->servers as $serverLabel => $serverUrl)
                <option value="{{ rtrim($serverUrl, '/') }}">{{ $serverLabel }}</option>
            @endforeach
        </select>
    @endif

    <ul class="mt-8 space-y-6">
        @foreach ($spec->sections as $section)
            <li>
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $section->name }}</span>
                <ul class="mt-2 space-y-1 border-l border-slate-200 dark:border-slate-800">
                    @foreach ($section->pages as $sectionPage)
                        <li>
                            <a href="{{ $links->page($sectionPage) }}"
                               @if (($current ?? null) === 'page:'.$sectionPage->id) aria-current="page" @endif
                               class="-ml-px block border-l py-1 pl-3 text-sm hover:border-indigo-500 hover:text-slate-900 dark:hover:text-white {{ ($current ?? null) === 'page:'.$sectionPage->id ? 'border-indigo-500 font-medium text-slate-900 dark:text-white' : 'border-transparent text-slate-600 dark:text-slate-400' }}">
                                {{ $sectionPage->title }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </li>
        @endforeach

        {{-- A version heading rather than "(v2)" on twenty group labels: the
             sidebar is scanned vertically, and one heading over eight groups
             reads faster than eight suffixes. Groups arrive newest version
             first, so this only has to notice the changes. --}}
        @php($sidebarVersion = false)

        @foreach ($spec->groups as $group)
            @if ($spec->isVersioned() && $sidebarVersion !== $group->version)
                @php($sidebarVersion = $group->version)
                <li class="border-t border-slate-200 pt-4 dark:border-slate-800">
                    <span class="font-mono text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">
                        {{ $group->version === null ? 'Any version' : ($spec->apiVersion($group->version)?->label() ?? $group->version) }}
                    </span>
                </li>
            @endif

            <li>
                <a href="{{ $links->group($group) }}" class="text-xs font-semibold uppercase tracking-wider text-slate-500 hover:text-slate-900 dark:hover:text-white">
                    {{ $group->name }}
                </a>
                <ul class="mt-2 space-y-1 border-l border-slate-200 dark:border-slate-800">
                    @foreach ($group->endpoints as $endpoint)
                        <li>
                            <a href="{{ $links->endpoint($endpoint) }}"
                               @if (($current ?? null) === 'endpoint:'.$endpoint->id) aria-current="page" @endif
                               class="-ml-px flex items-center gap-2 border-l py-1 pl-3 text-sm hover:border-indigo-500 hover:text-slate-900 dark:hover:text-white {{ ($current ?? null) === 'endpoint:'.$endpoint->id ? 'border-indigo-500 font-medium text-slate-900 dark:text-white' : 'border-transparent text-slate-600 dark:text-slate-400' }}">
                                @include('lusen::partials.method-badge', ['method' => $endpoint->method, 'compact' => true])
                                <span class="truncate">{{ $endpoint->summary ?? $endpoint->path() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </li>
        @endforeach
    </ul>
</nav>
