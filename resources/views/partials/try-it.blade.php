{{--
    The request, sent from the page.

    Off unless `try_it.enabled` is on, limited to `try_it.methods`, and
    withdrawn by `#[ApiDoc(tryIt: false)]` - see Support\TryIt, which is the
    only thing that decides.

    The JSON is written with `<` and `&` escaped: it sits inside a <script>
    element, and a description containing `</script>` would otherwise end the
    element early and put the rest of the model into the page as markup.

    Nothing is rendered here but the request as data and a button the script
    reveals: the form is built from that JSON in the browser. Two reasons. The
    markup stays out of the way of a reader who has no JavaScript and would
    otherwise meet a form that cannot submit, and the request has exactly one
    description on the page - the same one the printed example was rendered
    from, so the call you send is the call you copied.
--}}
@if (\Lusen\Support\TryIt::enabled($endpoint, config('lusen.try_it')))
    <div class="lusen-try" data-lusen-try>
        <script type="application/json" data-lusen-request>@json(\Lusen\Support\RequestModel::for($endpoint, $spec->baseUrl), JSON_HEX_TAG | JSON_HEX_AMP)</script>

        <button type="button" data-lusen-try-open hidden
                class="mt-2 w-full rounded-md border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 hover:border-indigo-500 hover:text-slate-900 dark:border-slate-700 dark:text-slate-400 dark:hover:text-white">
            Try it
        </button>
    </div>
@endif
