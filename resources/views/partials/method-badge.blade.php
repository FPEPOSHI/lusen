{{--
    The class strings below are written out in full on purpose. Tailwind scans
    source for literal class names, so composing them from $method->tone() at
    runtime would leave them out of the built CSS.
--}}
@php
    $classes = match ($method->tone()) {
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20',
        default => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400 dark:ring-sky-500/20',
    };
@endphp
<span class="inline-flex shrink-0 items-center rounded font-mono font-semibold uppercase ring-1 ring-inset {{ $classes }} {{ ($compact ?? false) ? 'px-1 py-0.5 text-[10px]' : 'px-2 py-1 text-xs' }}">
    {{ $method->value }}
</span>
