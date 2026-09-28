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
    /**
     * Anything a double-click can run, on every system a game runs on (user, 2026-09-28: "everything
     * executable"). ⚠ Not archives (.zip, .7z, .rar): they also carry fonts. Not web pages (.php,
     * .html): a sharing site's page is what most links will be.
     */
    public const PROGRAM_EXTENSIONS = [
        // Windows: programs, installers, scripts, shortcuts that run a command
        'exe', 'msi', 'msp', 'msix', 'msixbundle', 'appx', 'appxbundle', 'application', 'appref-ms',
        'com', 'scr', 'pif', 'cpl', 'dll', 'sys', 'bat', 'cmd', 'ps1', 'psm1', 'vb', 'vbs', 'vbe', 'js', 'jse',
        'wsf', 'wsh', 'wsc', 'hta', 'msc', 'reg', 'lnk', 'url', 'scf', 'inf', 'chm', 'gadget', 'xbap',
        'jar', 'jnlp',
        // Documents that carry macros
        'docm', 'xlsm', 'pptm',
        // Disk images: they open as a drive, a common way to hand over a program
        'iso', 'img', 'vhd', 'vhdx',
        // macOS
        'dmg', 'pkg', 'mpkg', 'app', 'command', 'scpt', 'applescript', 'workflow', 'kext', 'prefpane',
        'osax', 'terminal',
        // Linux, Steam Deck
        'sh', 'bash', 'zsh', 'csh', 'ksh', 'run', 'bin', 'appimage', 'deb', 'rpm', 'snap', 'flatpak',
        'flatpakref', 'desktop',
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

        $path = rawurldecode($parts['path'] ?? '');
        $names = self::isRepositoryPage(strtolower($parts['host'] ?? ''), $path) ? [] : [$path];

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

    /** Where source code is hosted: a repository's name is not a file, whatever dot it carries. */
    private const CODE_HOSTS = ['github.com', 'gitlab.com', 'codeberg.org', 'bitbucket.org', 'gitea.com', 'git.sr.ht'];

    /**
     * The page of a repository — github.com/mrdoob/three.js, codeberg.org/user/tools.app — rather
     * than a file in it. Measured on 2026-09-28: those were refused for their ".js" and ".app".
     *
     * ⚠ Only the page: a file inside (releases/download/…, raw/…, blob/…/install.sh) has more
     * segments and is read as usual. GitLab nests projects in groups at any depth, and every one of
     * its file addresses goes through "/-/", so a GitLab path without it is a project page.
     */
    private static function isRepositoryPage(string $host, string $path): bool
    {
        $host = preg_replace('/^www\./', '', $host);
        if (! in_array($host, self::CODE_HOSTS, true)) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        if ($host === 'gitlab.com') {
            return ! str_contains($path, '/-/');
        }

        // git.sr.ht puts the owner behind "~": ~user/repo is the same two segments.
        return count($segments) === 2;
    }
}
