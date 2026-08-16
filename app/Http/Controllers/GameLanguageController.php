<?php

namespace App\Http\Controllers;

use App\Services\CatalogStore;
use App\Services\GameLanguage;
use Illuminate\Http\Request;

/**
 * The one-click switch in the title bar saying which language somebody plays in.
 *
 * ⚠ Deliberately NOT on ProfileController any more, and outside the auth group: a visitor with no
 * account plays in a language too, and correcting the site's guess is not an account operation. It
 * mirrors LocaleController, which has always written the interface language for everybody.
 *
 * Separate from the profile form for the reason that route always carried: ProfileController's
 * update() requires a username and revalidates it, so a one-click switch would fail for anybody
 * whose name predates the current rules — and would say so about the wrong field.
 */
class GameLanguageController extends Controller
{
    public function switch(Request $request)
    {
        $request->validate([
            // ⚠ Validated against the CATALOGUE, not the interface locales: ninety languages can
            // be played in while twenty can be read in.
            'game_language' => 'nullable|string|in:'
                . implode(',', array_keys(CatalogStore::languageChoices())),
        ]);

        GameLanguage::remember($request->input('game_language') ?: null);

        return back();
    }
}
