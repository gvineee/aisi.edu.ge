<?php

namespace Tests\Unit;

use App\Domain\Content\PublicExcerpt;
use PHPUnit\Framework\TestCase;

class PublicExcerptTest extends TestCase
{
    public function test_drops_attachment_noise_and_keeps_georgian_intact(): void
    {
        // "ე" shares its last byte with "—": a byte-wise trim broke the page.
        $this->assertSame('სასწავლო წლის დასაწყისი სკოლაშე', PublicExcerpt::clean('სასწავლო წლის დასაწყისი სკოლაშე'));
        $this->assertSame('ანგარიში', PublicExcerpt::clean('ანგარიში — Download report_2023.pdf (120 KB)'));
        $this->assertNull(PublicExcerpt::clean('Download file.pdf'));
        $this->assertNull(PublicExcerpt::clean(null));

        foreach (['ე', 'ბ', 'ა', 'ი', 'ო', 'უ'] as $letter) {
            $this->assertTrue(mb_check_encoding((string) PublicExcerpt::clean("ტექსტ{$letter}"), 'UTF-8'));
            $this->assertSame("ტექსტ{$letter}", PublicExcerpt::clean("ტექსტ{$letter}"));
        }
    }
}
