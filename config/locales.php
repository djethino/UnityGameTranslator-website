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
        'en' => ['name' => 'English', 'native' => 'English', 'flag' => '🇬🇧', 'rtl' => false],
        // Others alphabetically by ISO code
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'flag' => '🇸🇦', 'rtl' => true],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'flag' => '🇩🇪', 'rtl' => false],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'flag' => '🇪🇸', 'rtl' => false],
        'fr' => ['name' => 'French', 'native' => 'Français', 'flag' => '🇫🇷', 'rtl' => false],
        'he' => ['name' => 'Hebrew', 'native' => 'עברית', 'flag' => '🇮🇱', 'rtl' => true],
        'hi' => ['name' => 'Hindi', 'native' => 'हिन्दी', 'flag' => '🇮🇳', 'rtl' => false],
        'id' => ['name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'flag' => '🇮🇩', 'rtl' => false],
        'it' => ['name' => 'Italian', 'native' => 'Italiano', 'flag' => '🇮🇹', 'rtl' => false],
        'ja' => ['name' => 'Japanese', 'native' => '日本語', 'flag' => '🇯🇵', 'rtl' => false],
        'ko' => ['name' => 'Korean', 'native' => '한국어', 'flag' => '🇰🇷', 'rtl' => false],
        'nl' => ['name' => 'Dutch', 'native' => 'Nederlands', 'flag' => '🇳🇱', 'rtl' => false],
        'pl' => ['name' => 'Polish', 'native' => 'Polski', 'flag' => '🇵🇱', 'rtl' => false],
        'pt' => ['name' => 'Portuguese', 'native' => 'Português', 'flag' => '🇧🇷', 'rtl' => false],
        'ru' => ['name' => 'Russian', 'native' => 'Русский', 'flag' => '🇷🇺', 'rtl' => false],
        'th' => ['name' => 'Thai', 'native' => 'ไทย', 'flag' => '🇹🇭', 'rtl' => false],
        'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'flag' => '🇹🇷', 'rtl' => false],
        'vi' => ['name' => 'Vietnamese', 'native' => 'Tiếng Việt', 'flag' => '🇻🇳', 'rtl' => false],
        'zh' => ['name' => 'Chinese', 'native' => '简体中文', 'flag' => '🇨🇳', 'rtl' => false],
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
