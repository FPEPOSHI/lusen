{{--
    The call to action.

    Deliberately not in the sidebar. That column is how a reader gets between
    pages, and a coloured button sitting in it competes with the navigation on
    every screen of every page - the reader came for the reference, and the
    place to ask is where they finish reading, not where they are trying to
    look something up.

    `$actionClass` lets the caller pick the shape; everything else is fixed
    here so the label and the destination cannot drift.
--}}
@php($action = \Lusen\Support\Product::action(config('lusen.product')))

@if ($action)
    <a href="{{ $action['url'] }}" class="{{ $actionClass ?? 'block rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white hover:bg-indigo-500' }}">
        {{ $action['label'] }}
    </a>

    @if (($action['note'] ?? null) && ($actionNote ?? true))
        <p class="mt-2 text-xs text-slate-500">{{ $action['note'] }}</p>
    @endif
@endif
