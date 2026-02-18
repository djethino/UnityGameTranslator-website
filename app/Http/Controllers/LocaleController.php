<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocaleController extends Controller
{
    public function switch(Request $request, string $locale)
    {
        $supportedLocales = array_keys(config('locales.supported', []));

        if (!in_array($locale, $supportedLocales)) {
            $locale = config('locales.default', 'en');
        }

        // Save to session for all users
        session(['locale' => $locale]);

        // Save to database for authenticated users
        if (Auth::check()) {
            Auth::user()->update(['locale' => $locale]);
        }

        return redirect()->back()->setTargetUrl(
            url()->previous(config('app.url'))
        );
    }
}
