{{--
    One endpoint, rendered self-contained.

    "Self-contained" is a hard requirement, not a nicety: this markup is what
    a retrieval model sees when it fetches a single page or a single fragment.
    It must repeat the base URL, the auth requirement, every parameter and a
    complete example. Never write "see the authentication section above" here
    - the reader may not have an above.

    Two columns from `xl` up: what the endpoint is on the left, what a call to
    it looks like on the right. That is the shape every API reference a
    developer already reads has settled on, and it earns its keep - the
    parameter you are reading about and the example that uses it are on screen
    at the same time, rather than a scroll apart. One column below that, in
    document order: reference first, then the examples.
--}}
@php($standalone = $standalone ?? false)
{{-- The same partial sits at two depths, so its headings cannot be fixed:
     on the index it hangs under a group (h2), and on its own page the <h1>
     is the endpoint title. Screen readers navigate by this outline, so a
     skipped level is a real defect, not a cosmetic one. --}}
@php($titleLevel = 3)
@php($sectionLevel = $standalone ? 2 : 4)

<article id="{{ $endpoint->slug() }}"
         class="scroll-mt-8 {{ $standalone ? 'mt-6' : 'rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900/40' }}">

    <div class="flex flex-wrap items-center gap-3">
        @include('lusen::partials.method-badge', ['method' => $endpoint->method])

        <code class="min-w-0 break-all font-mono text-sm text-slate-900 dark:text-white">{{ $endpoint->path() }}</code>

        @if ($endpoint->authenticated)
            <span class="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                Requires authentication
            </span>
        @endif

        @if ($endpoint->rateLimit)
            <span class="inline-flex items-center rounded bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                {{ $endpoint->rateLimit->label() }}
            </span>
        @endif

        @if ($endpoint->deprecated)
            <span class="inline-flex items-center rounded bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-400">
                Deprecated
            </span>
        @endif

        {{-- Shown whenever the route declares one, even on an API with a
             single version: it costs one badge and it means a fragment of
             this page quoted anywhere else still says what it calls. --}}
        @if ($endpoint->version)
            <span class="inline-flex items-center rounded bg-slate-100 px-2 py-1 font-mono text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                {{ $endpoint->version }}
            </span>
        @endif
    </div>

    {{-- Above everything else on purpose. Somebody reading v1 has to learn
         that v2 exists before they finish copying the example, not after. --}}
    @php($successor = $spec->endpoint($endpoint->supersededBy))

    @if ($successor)
        <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            A newer version of this operation is available:
            <a href="{{ $links->endpoint($successor) }}" class="font-medium underline">{{ $successor->method->value }} {{ $successor->path() }}</a>.
        </p>
    @endif

    @unless ($standalone)
        <h{{ $titleLevel }} class="mt-4 text-lg font-semibold tracking-tight text-slate-900 dark:text-white">
            <a href="{{ $links->endpoint($endpoint) }}" class="hover:underline">{{ $endpoint->title() }}</a>
        </h{{ $titleLevel }}>
    @endunless

    @if ($endpoint->description)
        {{-- Rendered as Markdown, not printed as text: a description written
             with a list or a code fence is written that way on purpose, and
             authors reach for Markdown here as soon as they notice it works. --}}
        <div class="lusen-prose mt-2 max-w-2xl text-sm">{!! \Lusen\Support\MarkdownDocument::render($endpoint->description)->html !!}</div>
    @endif

    <p class="mt-3 text-sm text-slate-500">
        @if ($spec->baseUrl)
            Full URL: <code class="font-mono text-slate-700 dark:text-slate-300">{{ rtrim($spec->baseUrl, '/') }}{{ $endpoint->path() }}</code>.
        @endif
        {{ $endpoint->authenticated
            ? 'Send a bearer token in the Authorization header.'
            : 'No authentication required.' }}
    </p>

    <div class="xl:grid xl:grid-cols-[minmax(0,1fr)_30rem] xl:items-start xl:gap-8">

        {{-- What the endpoint takes and what it answers with. --}}
        <div class="min-w-0">

            @foreach (\Lusen\Ir\Enums\ParameterLocation::cases() as $location)
                @php($parameters = $endpoint->parametersIn($location))

                @if ($parameters)
                    @php($heading = \Lusen\Support\Outline::parameterHeading($location))
                    <h{{ $sectionLevel }} id="{{ \Lusen\Support\Outline::id($endpoint, $heading) }}" class="mt-6 scroll-mt-8 text-xs font-semibold uppercase tracking-wider text-slate-500">
                        {{ $heading }}
                    </h{{ $sectionLevel }}>

                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-800">
                                    <th scope="col" class="py-2 pr-4 font-medium">Name</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Type</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Required</th>
                                    <th scope="col" class="py-2 font-medium">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($parameters as $parameter)
                                    <tr class="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                                        <td class="py-2 pr-4 font-mono text-slate-900 dark:text-white">{{ $parameter->name }}</td>
                                        <td class="py-2 pr-4 text-slate-600 dark:text-slate-400">{{ $parameter->schema->label() }}</td>
                                        <td class="py-2 pr-4 text-slate-600 dark:text-slate-400">{{ $parameter->required ? 'yes' : 'no' }}</td>
                                        <td class="py-2 text-slate-600 dark:text-slate-400">{{ $parameter->description }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endforeach

            @if ($endpoint->responses)
                {{-- The statuses and their fields. The bodies themselves are in
                     the column beside this one: what a field means is reference
                     and belongs with the parameters, what one looks like is an
                     example and belongs with the request. --}}
                <h{{ $sectionLevel }} id="{{ \Lusen\Support\Outline::id($endpoint, 'Responses') }}" class="mt-6 scroll-mt-8 text-xs font-semibold uppercase tracking-wider text-slate-500">Responses</h{{ $sectionLevel }}>

                <div class="mt-3 space-y-5">
                    @foreach ($endpoint->responses as $response)
                        @php($tone = $response->isSuccess() ? 'ok' : ($response->status >= 500 ? 'bad' : 'warn'))
                        <div>
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="lusen-status lusen-status-{{ $tone }}">{{ $response->status }}</span>
                                <span class="text-sm text-slate-600 dark:text-slate-400">{{ $response->label() }}</span>
                            </p>

                            @if ($response->schema)
                                @include('lusen::partials.schema-table', ['schema' => $response->schema])
                            @endif

                            @if ($response->examples === [] && $response->status === 204)
                                <p class="mt-1 text-sm text-slate-500">No response body.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- The call itself. Sticky on its own page, where the reference beside
             it is long enough to scroll past. --}}
        <div class="mt-8 xl:mt-6 {{ $standalone ? 'xl:sticky xl:top-8' : '' }}">

            {{-- The request example is the most load-bearing block on the page:
                 a reader copies it, and an agent reads it to learn the exact
                 shape of the call. It leads the column for that reason. --}}
            <h{{ $sectionLevel }} id="{{ \Lusen\Support\Outline::id($endpoint, 'Example request') }}" class="scroll-mt-8 text-xs font-semibold uppercase tracking-wider text-slate-500">Example request</h{{ $sectionLevel }}>

            {{-- Driven by ui.snippets, so the config lists what is actually
                 rendered. Stacked and labelled, which is what reads with no
                 JavaScript and what a model retrieving this HTML sees; the
                 script turns the set into tabs. --}}
            <div class="lusen-snippets" data-lusen-tabs="snippet">
                @foreach (\Lusen\Support\Snippets::languages(config('lusen.ui.snippets', ['curl'])) as $language => $label)
                    @include('lusen::partials.code', [
                        'label' => $label,
                        'language' => $language === 'curl' ? 'bash' : 'javascript',
                        'code' => \Lusen\Support\Snippets::render($language, $endpoint, $spec->baseUrl),
                    ])
                @endforeach
            </div>

            @include('lusen::partials.try-it', ['endpoint' => $endpoint, 'spec' => $spec])

            @php($bodies = array_values(array_filter($endpoint->responses, fn ($response): bool => $response->examples !== [])))

            @if ($bodies !== [])
                <h{{ $sectionLevel }} class="mt-6 scroll-mt-8 text-xs font-semibold uppercase tracking-wider text-slate-500">Example response</h{{ $sectionLevel }}>

                {{-- One status at a time where the script runs: an endpoint
                     that documents a 200, a 404 and a 422 otherwise puts two
                     bodies nobody asked for between the reader and the one
                     they did. Stacked and labelled without it. --}}
                <div class="lusen-snippets" data-lusen-tabs="response">
                    @foreach ($bodies as $response)
                        @foreach ($response->examples as $example)
                            @include('lusen::partials.code', [
                                'label' => (string) $response->status,
                                'language' => str_contains($example->contentType, 'json') ? 'json' : 'text',
                                'code' => $example->render(),
                            ])
                        @endforeach
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</article>
