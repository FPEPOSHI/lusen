{{--
    Ask an assistant about this page, with the question already written.

    Plain links, so they work with JavaScript off like every other link here,
    and rendered only when the page has an absolute address for the prompt to
    name - see Support\AskAi.
--}}
@php($askLinks = \Lusen\Support\AskAi::links(config('lusen.ui.ask_ai'), $askUrl ?? null, $askSubject ?? '', $spec->title))

@foreach ($askLinks as $askLabel => $askHref)
    <a href="{{ $askHref }}" target="_blank" rel="noopener noreferrer"
       class="{{ $askClass ?? 'underline hover:text-slate-900 dark:hover:text-white' }}">
        Ask {{ $askLabel }}
    </a>
@endforeach
