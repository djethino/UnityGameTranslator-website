{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@php $locales = config('locales.supported', []); @endphp
@foreach($locales as $code => $locale)
<url>
<loc>{{ url('/' . $code) }}</loc>
@foreach($locales as $altCode => $altLocale)
<xhtml:link rel="alternate" hreflang="{{ $altCode }}" href="{{ url('/' . $altCode) }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ url('/') }}"/>
<changefreq>daily</changefreq>
<priority>1.0</priority>
</url>
@endforeach
@foreach($locales as $code => $locale)
<url>
<loc>{{ url('/' . $code . '/games') }}</loc>
@foreach($locales as $altCode => $altLocale)
<xhtml:link rel="alternate" hreflang="{{ $altCode }}" href="{{ url('/' . $altCode . '/games') }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ route('games.index') }}"/>
<changefreq>daily</changefreq>
<priority>0.9</priority>
</url>
@endforeach
@foreach($locales as $code => $locale)
<url>
<loc>{{ url('/' . $code . '/docs') }}</loc>
@foreach($locales as $altCode => $altLocale)
<xhtml:link rel="alternate" hreflang="{{ $altCode }}" href="{{ url('/' . $altCode . '/docs') }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ route('docs') }}"/>
<changefreq>weekly</changefreq>
<priority>0.7</priority>
</url>
@endforeach
@foreach($locales as $code => $locale)
<url>
<loc>{{ url('/' . $code . '/legal') }}</loc>
@foreach($locales as $altCode => $altLocale)
<xhtml:link rel="alternate" hreflang="{{ $altCode }}" href="{{ url('/' . $altCode . '/legal') }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ route('legal.mentions') }}"/>
<changefreq>monthly</changefreq>
<priority>0.3</priority>
</url>
@endforeach
@foreach($locales as $code => $locale)
<url>
<loc>{{ url('/' . $code . '/privacy') }}</loc>
@foreach($locales as $altCode => $altLocale)
<xhtml:link rel="alternate" hreflang="{{ $altCode }}" href="{{ url('/' . $altCode . '/privacy') }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ route('legal.privacy') }}"/>
<changefreq>monthly</changefreq>
<priority>0.3</priority>
</url>
@endforeach
@foreach($locales as $code => $locale)
<url>
<loc>{{ url('/' . $code . '/terms') }}</loc>
@foreach($locales as $altCode => $altLocale)
<xhtml:link rel="alternate" hreflang="{{ $altCode }}" href="{{ url('/' . $altCode . '/terms') }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ route('legal.terms') }}"/>
<changefreq>monthly</changefreq>
<priority>0.3</priority>
</url>
@endforeach
</urlset>
