{{-- Search over a prebuilt static index: no server, no search service.

     Hidden until JavaScript enables it, because a search box that does
     nothing is worse than no search box. Everything on the site remains
     reachable through the navigation below without it. --}}
<form class="lusen-search" role="search" hidden data-lusen-search>
    <label class="sr-only" for="lusen-search-input">Search the documentation</label>
    <input id="lusen-search-input"
           type="search"
           autocomplete="off"
           placeholder="Search…"
           class="w-full rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-white">
    <ul class="lusen-search-results" role="listbox" aria-label="Search results" hidden></ul>
    <p class="lusen-search-empty" hidden>No matches.</p>
</form>
