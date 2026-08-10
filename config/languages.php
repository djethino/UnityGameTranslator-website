<?php

/**
 * Languages a translation may be published in.
 *
 * This list IS the contract. The upload endpoint validates target_language against it, and the
 * mod carries the same names (UnityGameTranslator.Common.Languages) so that what it sends is
 * accepted. A name added, removed or respelled on one side only shows up as an upload refused by
 * a validation error the contributor did not cause and cannot read.
 *
 * A name, not a code: five of these have no ISO 639-1 code at all, and the name is what travels
 * everywhere — stored here, resolved by the mod, written into the game's config.
 *
 * It does NOT describe what any model can translate. Models are picked from a catalogue and most
 * will attempt any pair with varying success; this is what the site accepts to host.
 */
return [
    // Major languages (most common for game translations)
    'English',
    'French',
    'German',
    'Spanish',
    'Italian',
    'Portuguese',
    'Russian',
    'Polish',
    'Japanese',
    'Korean',
    'Simplified Chinese',
    'Traditional Chinese',
    'Arabic',
    'Turkish',
    'Dutch',
    'Swedish',
    'Danish',
    'Norwegian Bokmål',
    'Finnish',
    'Czech',
    'Hungarian',
    'Romanian',
    'Greek',
    'Thai',
    'Vietnamese',
    'Indonesian',
    'Malay',
    'Ukrainian',
    'Bulgarian',

    // Other Indo-European
    'Slovak',
    'Croatian',
    'Serbian',
    'Slovenian',
    'Lithuanian',
    'Latvian',
    'Estonian',
    'Norwegian Nynorsk',
    'Icelandic',
    'Faroese',
    'Irish',
    'Welsh',
    'Catalan',
    'Galician',
    'Basque',
    'Luxembourgish',
    'Afrikaans',
    'Macedonian',
    'Bosnian',
    'Albanian',
    'Armenian',
    'Belarusian',
    'Persian',
    'Dari',
    'Tajik',

    // South Asian
    'Hindi',
    'Bengali',
    'Urdu',
    'Punjabi',
    'Gujarati',
    'Marathi',
    'Tamil',
    'Telugu',
    'Kannada',
    'Malayalam',
    'Oriya',
    'Sinhala',
    'Nepali',
    'Assamese',
    'Sindhi',

    // East/Southeast Asian
    'Cantonese',
    'Burmese',
    'Khmer',
    'Lao',
    'Tagalog',
    'Cebuano',
    'Javanese',
    'Sundanese',

    // Turkic
    'Azerbaijani',
    'Uzbek',
    'Kazakh',
    'Bashkir',
    'Tatar',

    // Semitic/Afro-Asiatic
    'Hebrew',
    'Maltese',
    'Egyptian Arabic',
    'Levantine Arabic',
    'Moroccan Arabic',

    // Other
    'Georgian',
    'Swahili',
    'Haitian Creole',
];
