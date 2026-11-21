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

/**
 * How the in-browser SVG avatar moves with the voice (8.2.0): pure functions from an
 * AnalyserNode's spectrum to a mouth shape and a small head motion. No DOM, no network, no
 * dependency; ui.js applies the results to the avatar on every animation frame, for text to
 * speech and for live voice alike.
 *
 * The motion is deliberately small (at most 1.5 px of lift and 2 degrees of tilt) so it reads as
 * "talking", not as bouncing. ui.js runs none of it when the avatar animation setting is off or the
 * learner asks the system for reduced motion.
 *
 * @module     local_ai_course_assistant/avatar_motion
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** Largest head lift in SVG pixels. */
    var MAX_LIFT = 1.5;
    /** Largest head tilt in degrees. */
    var MAX_TILT = 2;
    /** Mouth level below which the mouth reads as closed. */
    var OPEN_THRESHOLD = 0.08;

    /**
     * Speech energy (0..1) from byte frequency data: the mean of bins 2 to 20 (roughly the voice
     * band at fftSize 256), scaled so ordinary speech reaches about 1. Same measure the mouth sync
     * used before 8.2.0.
     *
     * @param {Uint8Array|number[]} data
     * @returns {number}
     */
    var levelFromSpectrum = function(data) {
        if (!data || data.length < 21) {
            return 0;
        }
        var sum = 0;
        for (var i = 2; i <= 20; i++) {
            sum += data[i];
        }
        return Math.max(0, Math.min(1, sum / 19 / 90));
    };

    /**
     * Ease the level: open fast, close more slowly, so the mouth doesn't flicker between syllables.
     *
     * @param {number} previous
     * @param {number} next
     * @returns {number}
     */
    var smooth = function(previous, next) {
        var k = next > previous ? 0.6 : 0.2;
        return previous + (next - previous) * k;
    };

    /**
     * The mouth for a level.
     *
     * @param {number} level 0..1
     * @returns {{open: string, openOpacity: string, smileOpacity: string}} CSS values for the open
     *     mouth's transform and opacity and the smile's opacity
     */
    var mouth = function(level) {
        var l = Math.max(0, Math.min(1, level || 0));
        return {
            open: 'scale(' + (1 + 0.15 * l).toFixed(3) + ',' + l.toFixed(3) + ')',
            openOpacity: l > OPEN_THRESHOLD ? '1' : '0',
            smileOpacity: String(Math.max(0.05, 1 - l * 1.3).toFixed(3)),
        };
    };

    /**
     * A small head motion for a level at a moment: a lift with the voice and a slow sway.
     *
     * @param {number} level 0..1
     * @param {number} timeMs Animation time
     * @returns {string} A CSS transform ('' at rest)
     */
    var head = function(level, timeMs) {
        var l = Math.max(0, Math.min(1, level || 0));
        if (l < 0.02) {
            return '';
        }
        var lift = -MAX_LIFT * l;
        var tilt = Math.sin((timeMs || 0) / 650) * MAX_TILT * l;
        return 'translateY(' + lift.toFixed(2) + 'px) rotate(' + tilt.toFixed(2) + 'deg)';
    };

    return {
        levelFromSpectrum: levelFromSpectrum,
        smooth: smooth,
        mouth: mouth,
        head: head,
        MAX_LIFT: MAX_LIFT,
        MAX_TILT: MAX_TILT,
    };
});
