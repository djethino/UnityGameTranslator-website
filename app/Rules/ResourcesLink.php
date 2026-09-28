<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A translation's resources link may lead to fonts, images or a .ugtpack — never straight to a program.
 *
 * What UGT Manager and UGT Mod accept from such a link is fonts, images and asset packs, and they
 * check every file they unpack. A link to an installer or a script sends somebody past all of it,
 * from a page that carries this site's name.
 *
 * ⚠ It can only see the address. A file-sharing page whose address names no file passes, and the
 * text beside the field says what the link must be for exactly that reason. The file name is looked
 * for in the path and in each query value (download.php?file=setup.exe).
 */
class ResourcesLink implements ValidationRule
{
    /** Programs, installers and scripts, on every system a game runs on. */
    public const PROGRAM_EXTENSIONS = [
        // Windows
        'exe', 'msi', 'msp', 'msix', 'msixbundle', 'appx', 'appxbundle', 'application', 'appref-ms',
        'com', 'scr', 'pif', 'cpl', 'dll', 'sys', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse',
        'wsf', 'wsh', 'hta', 'msc', 'reg', 'lnk', 'jar',
        // macOS
        'dmg', 'pkg', 'mpkg', 'app', 'command', 'scpt', 'applescript', 'workflow',
        // Linux, Steam Deck
        'sh', 'run', 'bin', 'appimage', 'deb', 'rpm', 'desktop',
        // Scripts a double-click runs once their interpreter is installed
        'py', 'pl', 'rb',
        // Android
        'apk',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (self::pointsToProgram($value)) {
            $fail(__('upload.resources_url_program'));
        }
    }

    public static function pointsToProgram(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return false;
        }

        $names = [rawurldecode($parts['path'] ?? '')];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            array_walk_recursive($query, function ($v) use (&$names) {
                if (is_string($v)) {
                    $names[] = $v;
                }
            });
        }

        foreach ($names as $name) {
            $extension = strtolower(pathinfo(rtrim($name, '/'), PATHINFO_EXTENSION));
            if ($extension !== '' && in_array($extension, self::PROGRAM_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }
}
