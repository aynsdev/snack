@props(['url'])
{{--
    The framework's version swaps in the Laravel logo when the slot reads
    "Laravel". That branch is gone: this application is never called that, so it
    was dead code describing somebody else's brand.

    The mark is a hosted PNG rather than the inline SVG in public/snack-icon.svg,
    because Gmail strips <svg> outright and blocks data: URIs. public/snack-mark.png
    is that same artwork rasterised at 2x — regenerate it from the SVG if the
    mark ever changes.

    asset() resolves against APP_URL, so this only loads once APP_URL points at a
    publicly reachable host; on http://snack.test a real mail client cannot fetch
    it. The wordmark below is live text for exactly that reason, and because most
    clients block remote images until the reader allows them: with the image
    missing or still blocked, the header degrades to "Snack" and the tagline
    rather than to nothing.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('snack-mark.png') }}" class="logo" alt="">
<span class="wordmark">{!! $slot !!}</span>
<span class="tagline">Small chats. Big work.</span>
</a>
</td>
</tr>
