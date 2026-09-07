{{--
    The strip across the top of every page.

    Above everything, including the narrow-screen bar, because a reader who
    scrolls past it once should not meet it again halfway down. It is ordinary
    markup with an ordinary link: with JavaScript off it shows and stays,
    which is the correct behaviour for an announcement.

    The dismiss button is hidden until the script can actually remember the
    choice - a close button that reopens on the next page is worse than none.
--}}
@php($banner = \Lusen\Support\Product::banner(config('lusen.product')))

@if ($banner)
    <div data-lusen-banner class="relative z-40 bg-indigo-600 px-4 py-2 text-center text-sm text-white sm:px-6">
        <span>{{ $banner['text'] }}</span>

        @if ($banner['url'])
            <a href="{{ $banner['url'] }}" class="ml-2 inline-block font-semibold underline underline-offset-2 hover:text-indigo-100">
                {{ $banner['label'] }}
            </a>
        @endif

        @if ($banner['dismissible'])
            <button type="button" data-lusen-banner-close hidden aria-label="Dismiss announcement"
                    class="absolute top-1/2 right-3 -translate-y-1/2 rounded p-1 text-indigo-100 hover:bg-indigo-500 hover:text-white">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        @endif
    </div>
@endif
