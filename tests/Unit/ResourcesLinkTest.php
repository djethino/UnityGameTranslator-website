<?php

namespace Tests\Unit;

use App\Rules\ResourcesLink;
use PHPUnit\Framework\TestCase;

/**
 * A resources link leads to fonts, images or a .ugtpack — never straight to a program.
 *
 * Only the address can be read, so a file-sharing page whose address names no file passes: that is
 * expected, and the texts beside the field say what the link must be for exactly that reason.
 */
class ResourcesLinkTest extends TestCase
{
    public function test_a_program_named_in_the_path_or_a_query_value_is_refused(): void
    {
        $this->assertTrue(ResourcesLink::pointsToProgram('https://example.com/setup.exe'));
        $this->assertTrue(ResourcesLink::pointsToProgram('https://example.com/files/Install%20Fonts.MSI'));
        $this->assertTrue(ResourcesLink::pointsToProgram('https://example.com/download.php?file=patch.bat'));
        $this->assertTrue(ResourcesLink::pointsToProgram('https://example.com/get?f[]=a.png&f[]=run.ps1'));

        // A file inside a repository is still a file.
        $this->assertTrue(ResourcesLink::pointsToProgram('https://github.com/user/fonts/releases/download/v1/setup.exe'));
        $this->assertTrue(ResourcesLink::pointsToProgram('https://github.com/user/repo/blob/main/install.sh'));
        $this->assertTrue(ResourcesLink::pointsToProgram('https://gitlab.com/group/project/-/raw/main/run.bat'));

        // Every system a game runs on: macOS installers and scripts, Linux and Steam Deck launchers.
        foreach (['fonts.dmg', 'Fonts.pkg', 'install.command', 'setup.scpt', 'install.sh', 'Fonts.AppImage', 'fonts.desktop', 'patch.py', 'run.cmd', 'fonts.iso', 'install.bash', 'readme.docm'] as $file) {
            $this->assertTrue(ResourcesLink::pointsToProgram('https://example.com/' . $file), $file);
        }
    }

    public function test_fonts_images_packs_and_pages_pass(): void
    {
        $this->assertFalse(ResourcesLink::pointsToProgram('https://example.com/fonts/NotoSans.ttf'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://example.com/title.png'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://example.com/game assets.ugtpack'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://mega.nz/file/AbC123#key'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://drive.google.com/file/d/xyz/view?usp=sharing'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://www.example.com/'));

        // File-sharing sites, as their addresses really look.
        foreach ([
            'https://mega.nz/file/AbC123#key-part',
            'https://drive.google.com/file/d/1AbC/view?usp=sharing',
            'https://www.dropbox.com/scl/fi/abc/fonts.zip?rlkey=xyz&dl=0',
            'https://www.mediafire.com/file/abc123/fonts.zip/file',
            'https://user.itch.io/game-fonts',
            'https://www.nexusmods.com/game/mods/123?tab=files',
            'https://pan.baidu.com/s/1AbC?pwd=1234',
        ] as $url) {
            $this->assertFalse(ResourcesLink::pointsToProgram($url), $url);
        }

        // A repository whose name carries a dot is a page, not a file (seen refused on 2026-09-28).
        foreach ([
            'https://github.com/mrdoob/three.js',
            'https://github.com/user/dotfiles.sh',
            'https://codeberg.org/user/tools.app',
            'https://gitlab.com/group/sub/project.js',
            'https://github.com/user/fonts/releases/download/v1.0/fonts.ugtpack',
            'https://raw.githubusercontent.com/user/repo/main/NotoSans.ttf',
        ] as $url) {
            $this->assertFalse(ResourcesLink::pointsToProgram($url), $url);
        }

        // Archives also carry fonts, and a sharing site's page is what most links are.
        $this->assertFalse(ResourcesLink::pointsToProgram('https://example.com/fonts.zip'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://example.com/mod/page.php?id=12'));
    }
}
