{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@php $locales = config('locales.supported', []); @endphp
@foreach($games as $game)
@foreach($locales as $code => $locale)
<url>
<loc>{{ url('/' . $code . '/games/' . $game->slug) }}</loc>
@foreach($locales as $altCode => $altLocale)
<xhtml:link rel="alternate" hreflang="{{ $altCode }}" href="{{ url('/' . $altCode . '/games/' . $game->slug) }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ route('games.show', $game) }}"/>
<lastmod>{{ $game->updated_at->toAtomString() }}</lastmod>
<changefreq>weekly</changefreq>
<priority>0.8</priority>
</url>
@endforeach
@endforeach
</urlset>
