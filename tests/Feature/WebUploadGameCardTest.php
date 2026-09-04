<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use App\Services\GameSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the upload form may make a game's card say.
 *
 * 🔴 **The form named the game and the card believed it.** A title and a cover URL travelled as
 * hidden fields and were written into the card as they came, so an upload carrying a real IGDB
 * id with a title of the uploader's choosing captured every later upload of that game, and a
 * cover hosted on the uploader's server made every visitor of the game's pages send it their
 * address. The id is the only thing the form is trusted for; what it names is asked of the
 * source again, server-side.
 */
class WebUploadGameCardTest extends TestCase
{
    use RefreshDatabase;

    private function upload(array $fields): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent(
            'translations.json',
            json_encode([
                '_uuid' => (string) Str::uuid(),
                'Hello ' . uniqid() => ['v' => 'Bonjour', 't' => 'H'],
            ])
        );

        return $this->actingAs(User::factory()->create())
            ->post(route('translations.store'), array_merge([
                'game_source' => 'igdb',
                'game_external_id' => 777,
                'source_language' => 'English',
                'target_language' => 'French',
                'status' => 'in_progress',
                'file' => $file,
            ], $fields));
    }

    public function test_the_card_carries_what_the_source_says_not_what_the_form_said(): void
    {
        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldReceive('getGame')->with(777, 'igdb')->andReturn([
                'id' => 777,
                'name' => 'The Real Title',
                'image_url' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/real.jpg',
                'source' => 'igdb',
            ]);
        });

        $this->upload([
            'game_name' => 'A Title Of My Choosing',
            'game_image_url' => 'https://tracker.example/pixel.png',
        ])->assertSessionHasNoErrors();

        $game = Game::where('igdb_id', 777)->firstOrFail();
        $this->assertSame('The Real Title', $game->name);
        $this->assertSame('https://images.igdb.com/igdb/image/upload/t_cover_big/real.jpg', $game->image_url);
    }

    public function test_when_the_source_is_silent_the_title_is_kept_and_the_cover_is_not(): void
    {
        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldReceive('getGame')->andReturn(null);
        });

        $this->upload([
            'game_name' => 'Offered Title',
            'game_image_url' => 'https://tracker.example/pixel.png',
        ])->assertSessionHasNoErrors();

        $game = Game::where('igdb_id', 777)->firstOrFail();
        $this->assertSame('Offered Title', $game->name, 'an outage must not refuse the upload');
        $this->assertNull($game->image_url, 'but a cover never comes from the form');
    }

    public function test_an_existing_card_never_takes_a_cover_from_the_form(): void
    {
        $existing = Game::create(['name' => 'Known Game', 'igdb_id' => 777]);

        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldReceive('getGame')->andReturn(null);
        });

        $this->upload([
            'game_name' => 'Known Game',
            'game_image_url' => 'https://tracker.example/pixel.png',
        ])->assertSessionHasNoErrors();

        $this->assertNull($existing->fresh()->image_url);
        $this->assertSame('Known Game', $existing->fresh()->name);
    }
}
