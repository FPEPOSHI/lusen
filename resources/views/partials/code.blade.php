{{-- A labelled code block.

     Highlighting happens while the page is generated, so there is no CDN
     request, no flash of unstyled code, and nothing to load before it reads
     correctly. The copy button is hidden until JavaScript reveals it, so a
     reader without JS sees a clean block rather than a dead control. --}}
@php($language = $language ?? 'bash')

<figure class="lusen-code">
    <figcaption class="lusen-code-head">
        <span class="lusen-code-label">{{ $label }}</span>
        <button type="button" class="lusen-copy" data-lusen-copy hidden>Copy</button>
    </figcaption>
    <pre class="lusen-code-body"><code class="language-{{ $language }}">{!! \Lusen\Support\Highlighter::highlight($code, $language) !!}</code></pre>
</figure>
