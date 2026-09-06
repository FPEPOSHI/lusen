{{--
    A 16px inline SVG, by name.

    Inline rather than a sprite or an icon font: the pages are flat files a
    crawler and a reader both fetch cold, and an extra request - or a font -
    to draw five glyphs would cost more than the glyphs. `currentColor` means
    a link's own colour carries into its icon with no second rule to keep in
    step.

    Decorative, always: every one of these sits beside its own label, so a
    screen reader announcing it would read the same thing twice.
--}}
@php($d = match ($name) {
    'markdown' => ['M14 3v4a1 1 0 0 0 1 1h4', 'M19 8v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7z', 'M9 13h6', 'M9 17h4'],
    'openapi' => ['M8 4a2 2 0 0 0-2 2v3a2 2 0 0 1-2 2 2 2 0 0 1 2 2v3a2 2 0 0 0 2 2', 'M16 4a2 2 0 0 1 2 2v3a2 2 0 0 0 2 2 2 2 0 0 0-2 2v3a2 2 0 0 1-2 2'],
    'copy' => ['M9 4h6a1 1 0 0 1 1 1v1H8V5a1 1 0 0 1 1-1z', 'M16 6h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h1'],
    'ask' => ['M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z', 'M18 15l.8 2.2L21 18l-2.2.8L18 21l-.8-2.2L15 18l2.2-.8z'],
    'edit' => ['M4 20h4l10-10a2.8 2.8 0 1 0-4-4L4 16v4z', 'M13.5 6.5l4 4'],
    default => [],
})

@if ($d !== [])
    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @foreach ($d as $path)
            <path d="{{ $path }}" />
        @endforeach
    </svg>
@endif
