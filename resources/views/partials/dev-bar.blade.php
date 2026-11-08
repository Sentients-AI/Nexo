{{--
    Development navigation, included by app.blade.php only when
    APP_ENV=local. Plain links (full page loads), outside the Vue app, so it
    adds no JavaScript and no shared props, and never renders in testing,
    staging, demo or production.
--}}
<nav aria-label="Development" lang="en" dir="ltr" class="border-b border-border bg-subtle text-sm">
    <ul class="mx-auto flex max-w-content flex-wrap items-center gap-x-4 gap-y-1 px-gutter py-2">
        <li class="text-xs font-medium tracking-wide text-fg-muted uppercase">Dev</li>
        <li><a class="text-fg underline-offset-4 hover:underline" href="{{ url('/') }}">Home</a></li>
        <li><a class="text-fg underline-offset-4 hover:underline" href="{{ url('/ms') }}" lang="ms">Bahasa Melayu</a></li>
        <li><a class="text-fg underline-offset-4 hover:underline" href="{{ url('/ar') }}" lang="ar">العربية</a></li>
        <li class="text-fg-muted" aria-hidden="true">·</li>
        <li><a class="text-fg underline-offset-4 hover:underline" href="{{ url('/dev/tokens') }}">Design tokens</a></li>
        <li><a class="text-fg underline-offset-4 hover:underline" href="{{ url('/dev/components') }}">Components</a></li>
    </ul>
</nav>
