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
        'pt' => ['name' => 'Portuguese', 'native' => 'Português', 'flag' => 'br', 'rtl' => false],
        'ru' => ['name' => 'Russian', 'native' => 'Русский', 'flag' => 'ru', 'rtl' => false],
        'th' => ['name' => 'Thai', 'native' => 'ไทย', 'flag' => 'th', 'rtl' => false],
        'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'flag' => 'tr', 'rtl' => false],
        'vi' => ['name' => 'Vietnamese', 'native' => 'Tiếng Việt', 'flag' => 'vn', 'rtl' => false],
        'zh' => ['name' => 'Chinese', 'native' => '简体中文', 'flag' => 'cn', 'rtl' => false],
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
