{{--
    Credentials for the playground, set once on the page that explains them.

    This is the only place a reader types a token, and every Try it dialog on
    the site reads it back - an API that wants a bearer token wants the same
    one on every endpoint, and retyping it per page is what stops people using
    the playground by the third endpoint.

    Hidden until the script can actually store it, and rendered only in HTML:
    the Markdown twin of this page has no form to offer, so it says nothing
    about one.
--}}
@php($setupAuth = \Lusen\Support\TryIt::configured(config('lusen.try_it')) ? \Lusen\Support\TryIt::auth($spec) : null)

@if ($setupAuth)
    <section class="mt-10 rounded-lg border border-slate-200 p-5 dark:border-slate-800" data-lusen-auth hidden>
        <h2 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white">Set up to test</h2>

        <p class="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-400">
            Put a credential here and every <strong class="font-medium text-slate-900 dark:text-white">Try it</strong>
            on this site will send it. Requests go from this browser straight to the API — the
            credential is never sent anywhere else, and never appears in the examples you copy.
        </p>

        <div class="mt-4 max-w-md space-y-3">
            @foreach ($setupAuth['headers'] as $header)
                <div>
                    <label for="lusen-auth-{{ \Lusen\Support\Str::slug($header) }}" class="block font-mono text-xs font-semibold text-slate-900 dark:text-white">
                        {{ $header }}
                    </label>
                    <input id="lusen-auth-{{ \Lusen\Support\Str::slug($header) }}"
                           type="password"
                           autocomplete="off"
                           data-lusen-auth-input="{{ $header }}"
                           placeholder="{{ $setupAuth['scheme'] === 'bearer' && $header === 'Authorization' ? 'token' : $header }}"
                           class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-1.5 font-mono text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-4">
            {{-- Revealed by the script only where the site allows the longer-lived
                 store, because a checkbox that cannot be honoured is a promise
                 about a credential, which is the worst kind to break. --}}
            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400" data-lusen-auth-remember hidden>
                <input type="checkbox" class="rounded border-slate-300 dark:border-slate-600">
                Remember on this browser
            </label>

            <button type="button" data-lusen-auth-clear
                    class="rounded-md border border-slate-200 px-3 py-1 text-sm text-slate-600 hover:border-rose-400 hover:text-rose-600 dark:border-slate-700 dark:text-slate-400">
                Forget
            </button>
        </div>

        <p class="mt-3 max-w-2xl text-xs text-slate-500" data-lusen-auth-note></p>
    </section>
@endif
