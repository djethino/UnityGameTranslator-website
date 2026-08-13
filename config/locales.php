<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | List of all supported locales with their native names and flags.
    | Add new locales here to support additional languages.
    |
    */

    'supported' => [
        // Default language first
        'en' => ['name' => 'English', 'native' => 'English', 'flag' => 'gb', 'rtl' => false],
        // Others alphabetically by ISO code
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'flag' => 'sa', 'rtl' => true],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'flag' => 'de', 'rtl' => false],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'flag' => 'es', 'rtl' => false],
        'fr' => ['name' => 'French', 'native' => 'Français', 'flag' => 'fr', 'rtl' => false],
        'he' => ['name' => 'Hebrew', 'native' => 'עברית', 'flag' => 'il', 'rtl' => true],
        'hi' => ['name' => 'Hindi', 'native' => 'हिन्दी', 'flag' => 'in', 'rtl' => false],
        'id' => ['name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'flag' => 'id', 'rtl' => false],
        'it' => ['name' => 'Italian', 'native' => 'Italiano', 'flag' => 'it', 'rtl' => false],
        'ja' => ['name' => 'Japanese', 'native' => '日本語', 'flag' => 'jp', 'rtl' => false],
        'ko' => ['name' => 'Korean', 'native' => '한국어', 'flag' => 'kr', 'rtl' => false],
        'nl' => ['name' => 'Dutch', 'native' => 'Nederlands', 'flag' => 'nl', 'rtl' => false],
        'pl' => ['name' => 'Polish', 'native' => 'Polski', 'flag' => 'pl', 'rtl' => false],
        // ⚠ Two Portuguese, both spelled out. A bare 'pt' used to mean Brazilian by implication,
        // and because nothing said so out loud the file drifted: it ended up half Brazilian and
        // half European (arquivo/ficheiro, tela/ecrã, usuário/utilizador in the same file), which
        // reads as careless to BOTH audiences. Steam lists them as two languages; so do we.
        'pt-BR' => ['name' => 'Portuguese (Brazil)', 'native' => 'Português (Brasil)', 'flag' => 'br', 'rtl' => false],
        'pt-PT' => ['name' => 'Portuguese (Portugal)', 'native' => 'Português (Portugal)', 'flag' => 'pt', 'rtl' => false],
        'ru' => ['name' => 'Russian', 'native' => 'Русский', 'flag' => 'ru', 'rtl' => false],
        'th' => ['name' => 'Thai', 'native' => 'ไทย', 'flag' => 'th', 'rtl' => false],
        'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'flag' => 'tr', 'rtl' => false],
        'vi' => ['name' => 'Vietnamese', 'native' => 'Tiếng Việt', 'flag' => 'vn', 'rtl' => false],
        'zh' => ['name' => 'Chinese', 'native' => '简体中文', 'flag' => 'cn', 'rtl' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aliases
    |--------------------------------------------------------------------------
    |
    | Codes that are not locales of ours but point at one without ambiguity.
    |
    | ⚠ This is NOT a "close enough" table and must never become one. The site refuses on
    | purpose to serve Simplified Chinese to somebody who asked for Traditional, or Spanish to
    | somebody who asked for Catalan — answering a request with a DIFFERENT language decides for
    | that person what else they read (see BrowserLocaleTest, and the catalogue's own
    | about.interface_fallback). An alias may only resolve a code that expresses NO preference
    | between our variants.
    |
    | 'pt' qualifies: it names no region, every standard resolves it to Brazilian (CLDR, and
    | RFC 4647's first-match rule), and it is what /pt/ served for its whole life. 'pt-PT' would
    | NOT qualify — it states a preference, and it has its own locale now.
    |
    */

    'aliases' => [
        'pt' => 'pt-BR',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    */

    'default' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Fallback Locale
    |--------------------------------------------------------------------------
    */

    'fallback' => 'en',
];
