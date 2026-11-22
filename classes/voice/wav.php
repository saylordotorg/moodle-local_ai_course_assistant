<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_course_assistant\voice;

defined('MOODLE_INTERNAL') || die();

/**
 * Wraps raw 16-bit little-endian PCM in a canonical 44-byte WAV (RIFF) header.
 *
 * Gemini text to speech returns bare PCM (s16le, 24 kHz, mono). The browser's decodeAudioData() and an <audio>
 * element both need a container, and WAV is the one that needs no encoder: a fixed header in front of the samples.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wav {
    /** @var int Bytes in the header this class writes. */
    public const HEADER_BYTES = 44;

    /** @var string MIME type to send with the result. */
    public const MIME = 'audio/wav';

    /**
     * WAV bytes for 16-bit PCM.
     *
     * A trailing odd byte (half a sample) is dropped, so the data chunk always holds whole samples.
     *
     * @param string $pcm Raw signed 16-bit little-endian samples, interleaved when there are several channels.
     * @param int $samplerate Samples per second, e.g. 24000.
     * @param int $channels 1 for mono.
     * @return string
     * @throws \coding_exception for a rate or channel count out of range
     */
    public static function from_pcm16(string $pcm, int $samplerate = 24000, int $channels = 1): string {
        if ($samplerate < 1000 || $samplerate > 384000 || $channels < 1 || $channels > 8) {
            throw new \coding_exception('Unsupported PCM format');
        }
        $blockalign = $channels * 2;
        $usable = strlen($pcm) - (strlen($pcm) % $blockalign);
        if ($usable !== strlen($pcm)) {
            $pcm = substr($pcm, 0, $usable);
        }
        $datasize = strlen($pcm);
        return 'RIFF'
            . pack('V', 36 + $datasize)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)                         // Size of the fmt chunk.
            . pack('v', 1)                          // Audio format 1 = PCM.
            . pack('v', $channels)
            . pack('V', $samplerate)
            . pack('V', $samplerate * $blockalign)  // Byte rate.
            . pack('v', $blockalign)
            . pack('v', 16)                         // Bits per sample.
            . 'data'
            . pack('V', $datasize)
            . $pcm;
    }

    /**
     * The sample rate named in a MIME type such as "audio/L16;codec=pcm;rate=24000", or $default.
     *
     * @param string $mime
     * @param int $default
     * @return int
     */
    public static function rate_from_mime(string $mime, int $default = 24000): int {
        if (preg_match('~(?:^|;)\s*rate=(\d{4,6})\b~i', $mime, $m)) {
            return (int) $m[1];
        }
        return $default;
    }
}
