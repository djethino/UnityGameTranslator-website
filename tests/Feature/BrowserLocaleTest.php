<?php

namespace Tests\Feature;

use App\Services\CatalogStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which interface language a machine gets, from what its browser announces.
 *
 * Every case here was wrong until 2026-08-11, and none of them was visible: nobody reports "your
 * site guessed my language", they just read it in English, or in the wrong script, and say nothing.
 */
class BrowserLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function localeFor(string $acceptLanguage): string
    {
        // ⚠ The session MUST be emptied between cases. The middleware stores its answer for
        // guests, so a second call in the same test would assert what the first one decided and
        // pass no matter what the header said — which is exactly what happened when this was
        // written without it.
        $this->flushSession();

        $response = $this->withHeaders(['Accept-Language' => $acceptLanguage])->get('/');
        $response->assertOk();

        return app()->getLocale();
    }

    public function test_a_plain_language_is_taken_as_is(): void
    {
        $this->assertSame('fr', $this->localeFor('fr'));
        $this->assertSame('de', $this->localeFor('de-DE,de;q=0.9'));
    }

    public function test_a_region_is_ignored_where_we_have_only_one_of_them(): void
    {
        // We have one French, so fr-CA is French. The region only carries information when the
        // interface actually has both — see the Portuguese case below.
        $this->assertSame('fr', $this->localeFor('fr-CA'));
        $this->assertSame('es', $this->localeFor('es-MX'));
    }

    public function test_the_two_portuguese_are_told_apart(): void
    {
        // Serving one to the other's audience is the same mistake as serving Simplified Chinese
        // to somebody who asked for Traditional, and it is refused for the same reason.
        $this->assertSame('pt-BR', $this->localeFor('pt-BR'));
        $this->assertSame('pt-PT', $this->localeFor('pt-PT'));

        // ⚠ Announced in lowercase, which clients do: a locale code is case-insensitive by
        // BCP 47. Comparing it as a byte string sent Portugal to Brazilian Portuguese.
        $this->assertSame('pt-PT', $this->localeFor('pt-pt'));
        $this->assertSame('pt-BR', $this->localeFor('PT-br'));

        // No region stated is not a preference between the two: CLDR and RFC 4647 both read a
        // bare 'pt' as Brazilian, and it is what /pt/ served for its whole life.
        $this->assertSame('pt-BR', $this->localeFor('pt'));
        $this->assertSame('pt-BR', $this->localeFor('pt;q=0.9,en;q=0.8'));
    }

    public function test_the_old_portuguese_address_still_leads_somewhere(): void
    {
        // /pt/ was linked to and indexed for as long as the site existed. It answers permanently,
        // never 404, and never the same page under two addresses.
        $this->get('/pt')->assertStatus(301)->assertRedirect('/pt-BR');
        $this->get('/pt/games')->assertStatus(301)->assertRedirect('/pt-BR/games');
        $this->get('/pt/games?sort=recent')->assertRedirect('/pt-BR/games?sort=recent');
    }

    public function test_the_old_code_for_hebrew_is_understood(): void
    {
        // Java and a number of runtimes still emit iw, the pre-1989 code. It used to match nothing
        // and quietly hand out English while `he` was right there in the list.
        $this->assertSame('he', $this->localeFor('iw'));
        $this->assertSame('he', $this->localeFor('iw-IL'));
        $this->assertSame('he', $this->localeFor('he-IL'));
    }

    public function test_simplified_chinese_is_recognised_however_it_is_spelled(): void
    {
        $this->assertSame('zh', $this->localeFor('zh'));
        $this->assertSame('zh', $this->localeFor('zh-CN'));
        $this->assertSame('zh', $this->localeFor('zh-Hans'));
        $this->assertSame('zh', $this->localeFor('zh-Hans-CN'));
    }

    public function test_traditional_chinese_is_not_served_simplified(): void
    {
        // The site's zh IS Simplified. Truncating zh-Hant-TW to zh handed the other script to
        // somebody who had said which one they wanted. The interface has no Traditional, so the
        // answer is the default — English — per the catalogue's interface_fallback decision.
        $this->assertSame('en', $this->localeFor('zh-Hant'));
        $this->assertSame('en', $this->localeFor('zh-TW'));
        $this->assertSame('en', $this->localeFor('zh-Hant-TW'));
    }

    public function test_a_language_the_interface_does_not_have_gets_english(): void
    {
        // Catalan, Basque, Breton. Falling back to Spanish or French would often be more useful
        // and is refused on purpose — see the catalogue's about.interface_fallback.
        $this->assertSame('en', $this->localeFor('ca-ES,ca;q=0.9'));
        $this->assertSame('en', $this->localeFor('eu'));
    }

    public function test_the_highest_priority_supported_language_wins(): void
    {
        // Catalan first, but we do not have it; Spanish is next and we do.
        $this->assertSame('es', $this->localeFor('ca;q=1.0,es;q=0.8,en;q=0.5'));
    }

    public function test_the_catalogue_resolves_a_locale_the_same_way_the_mod_does(): void
    {
        // Guarding the rule itself, not just its use: this is the PHP half of a resolution the mod
        // performs in C#, and the two answering differently is the failure worth catching.
        $this->assertSame('zh-tw', CatalogStore::canonicalTag('zh-Hant-TW'));
        $this->assertSame('zh', CatalogStore::canonicalTag('zh_CN'));
        $this->assertSame('he', CatalogStore::canonicalTag('IW'));
        $this->assertSame('nb', CatalogStore::canonicalTag('no'));
        $this->assertNull(CatalogStore::canonicalTag('xx-YY'));
        $this->assertNull(CatalogStore::canonicalTag(''));
        $this->assertNull(CatalogStore::canonicalTag(null));
    }
}
