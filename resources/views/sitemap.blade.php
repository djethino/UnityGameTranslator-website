<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
    @php $locales = config('locales.supported', []); @endphp
    <url>
        <loc>{{ url('/') }}</loc>
        @foreach($locales as $code => $locale)
        <xhtml:link rel="alternate" hreflang="{{ $code }}" href="{{ url('/') }}?lang={{ $code }}"/>
        @endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ url('/') }}"/>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{{ route('games.index') }}</loc>
        @foreach($locales as $code => $locale)
        <xhtml:link rel="alternate" hreflang="{{ $code }}" href="{{ route('games.index') }}?lang={{ $code }}"/>
        @endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ route('games.index') }}"/>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    <url>
        <loc>{{ route('docs') }}</loc>
        @foreach($locales as $code => $locale)
        <xhtml:link rel="alternate" hreflang="{{ $code }}" href="{{ route('docs') }}?lang={{ $code }}"/>
        @endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ route('docs') }}"/>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
    @foreach($games as $game)
    <url>
        <loc>{{ route('games.show', $game) }}</loc>
        @foreach($locales as $code => $locale)
        <xhtml:link rel="alternate" hreflang="{{ $code }}" href="{{ route('games.show', $game) }}?lang={{ $code }}"/>
        @endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ route('games.show', $game) }}"/>
        <lastmod>{{ $game->updated_at->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    @endforeach
</urlset>
