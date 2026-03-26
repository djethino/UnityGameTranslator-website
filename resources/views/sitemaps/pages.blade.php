{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@php
$locales = config('locales.supported', []);
$pages = [
    ['path' => '', 'changefreq' => 'daily', 'priority' => '1.0'],
    ['path' => 'games', 'changefreq' => 'daily', 'priority' => '0.9'],
    ['path' => 'docs', 'changefreq' => 'weekly', 'priority' => '0.7'],
    ['path' => 'legal', 'changefreq' => 'monthly', 'priority' => '0.3'],
    ['path' => 'privacy', 'changefreq' => 'monthly', 'priority' => '0.3'],
    ['path' => 'terms', 'changefreq' => 'monthly', 'priority' => '0.3'],
];
@endphp
@foreach($pages as $page)
<url>
<loc>{{ url('/' . $page['path']) }}</loc>
@foreach($locales as $code => $locale)
<xhtml:link rel="alternate" hreflang="{{ $code }}" href="{{ url('/' . $code . ($page['path'] ? '/' . $page['path'] : '')) }}"/>
@endforeach
<xhtml:link rel="alternate" hreflang="x-default" href="{{ url('/' . $page['path']) }}"/>
<changefreq>{{ $page['changefreq'] }}</changefreq>
<priority>{{ $page['priority'] }}</priority>
</url>
@endforeach
</urlset>
