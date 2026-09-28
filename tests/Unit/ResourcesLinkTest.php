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
    }

    public function test_fonts_images_packs_and_pages_pass(): void
    {
        $this->assertFalse(ResourcesLink::pointsToProgram('https://example.com/fonts/NotoSans.ttf'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://example.com/title.png'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://example.com/game assets.ugtpack'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://mega.nz/file/AbC123#key'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://drive.google.com/file/d/xyz/view?usp=sharing'));
        $this->assertFalse(ResourcesLink::pointsToProgram('https://www.example.com/'));
    }
}
