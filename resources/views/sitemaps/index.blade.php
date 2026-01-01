{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<sitemap>
<loc>{{ url('/sitemap-pages.xml') }}</loc>
<lastmod>{{ now()->toAtomString() }}</lastmod>
</sitemap>
@for($i = 1; $i <= App\Http\Controllers\SitemapController::getGameSitemapPages(); $i++)
<sitemap>
<loc>{{ url("/sitemap-games-{$i}.xml") }}</loc>
<lastmod>{{ now()->toAtomString() }}</lastmod>
</sitemap>
@endfor
</sitemapindex>
