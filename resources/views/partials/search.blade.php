{{-- Search over a prebuilt static index: no server, no search service.

     Hidden until JavaScript enables it, because a search box that does
     nothing is worse than no search box. Everything on the site remains
     reachable through the navigation below without it.

     The ARIA is the combobox pattern rather than a bare list: the results are
     a listbox the input owns, so a screen reader announces them, and
     `aria-activedescendant` lets the arrow keys move through the results while
     focus stays in the field. --}}
<form class="lusen-search" role="search" hidden data-lusen-search>
    <label class="sr-only" for="lusen-search-input">Search the documentation</label>

    <div class="lusen-search-field">
        <input id="lusen-search-input"
               type="search"
               autocomplete="off"
               spellcheck="false"
               placeholder="Search…"
               role="combobox"
               aria-expanded="false"
               aria-controls="lusen-search-results"
               aria-autocomplete="list"
               class="w-full rounded-md border border-slate-200 bg-white py-1.5 pr-12 pl-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-white">

        {{-- Filled in by the script, and only where the shortcut it advertises
             exists: a phone has no ⌘K, and printing a key nobody can press is
             a small lie repeated on every page. --}}
        <kbd class="lusen-search-hint" data-lusen-search-hint hidden></kbd>
    </div>

    <ul id="lusen-search-results" class="lusen-search-results" role="listbox" aria-label="Search results" hidden></ul>
    <p class="lusen-search-empty" hidden>No matches.</p>
</form>
