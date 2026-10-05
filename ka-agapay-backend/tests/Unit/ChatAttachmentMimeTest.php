<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\ChatController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Voice notes reach Gemini as audio.
 *
 * An Android voice note is detected on the server as video/mp4 (same MPEG-4
 * container). Sent to Gemini under that type it was refused -- "0 Frames
 * found" -- three times in production on 2026-10-04. As audio/mp4 the same
 * file is accepted.
 */
class ChatAttachmentMimeTest extends TestCase
{
    #[Test]
    #[TestDox('a voice note detected as video is sent to Gemini as audio')]
    public function voice_notes_go_as_audio(): void
    {
        $this->assertSame('audio/mp4', ChatController::geminiMime('video/mp4'));
        $this->assertSame('audio/mp4', ChatController::geminiMime('video/quicktime'));
        $this->assertSame('audio/3gpp', ChatController::geminiMime('video/3gpp'));
        $this->assertSame('audio/webm', ChatController::geminiMime('VIDEO/WEBM'));
    }

    #[Test]
    #[TestDox('photos and audio keep their own type')]
    public function others_unchanged(): void
    {
        foreach (['image/jpeg', 'image/png', 'audio/x-m4a', 'audio/mp4', 'audio/mpeg'] as $mime) {
            $this->assertSame($mime, ChatController::geminiMime($mime));
        }
    }
}
