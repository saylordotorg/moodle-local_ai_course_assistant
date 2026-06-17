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
 * Audio playback module for AI tutor chat using Web Speech API.
 *
 * @module     local_ai_course_assistant/audio_player
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {

    /** @type {SpeechSynthesisUtterance|null} Current utterance */
    let currentUtterance = null;
    /** @type {HTMLElement|null} Currently playing button */
    let currentPlayingButton = null;

    /**
     * Check if TTS is supported in this browser.
     *
     * @returns {boolean}
     */
    const isSupported = function() {
        return 'speechSynthesis' in window;
    };

    /**
     * Update button icon based on state.
     *
     * @param {HTMLElement} button
     * @param {string} state 'play' or 'pause'
     */
    const SVG_NS = 'http://www.w3.org/2000/svg';

    /**
     * Build an SVG element via the DOM (no innerHTML string, no template engine).
     * audio_player ships in the standalone CDN widget bundle, which has no
     * core/templates, so the glyph is constructed from constants here
     * (CONTRIB-10574 #94).
     *
     * @param {string} tag
     * @param {Object} attrs
     * @return {SVGElement}
     */
    const svgEl = function(tag, attrs) {
        const el = document.createElementNS(SVG_NS, tag);
        Object.keys(attrs).forEach(function(k) {
            el.setAttribute(k, attrs[k]);
        });
        return el;
    };

    /**
     * Update button icon based on state.
     *
     * @param {HTMLElement} button
     * @param {string} state 'play' or 'pause'
     */
    const updateButtonIcon = function(button, state) {
        const playing = (state === 'pause');
        const svg = svgEl('svg', {
            'class': 'local-ai-course-assistant__audio-icon',
            width: '16', height: '16', viewBox: '0 0 16 16',
            fill: 'currentColor', 'aria-hidden': 'true',
        });
        if (playing) {
            svg.appendChild(svgEl('rect', {x: '4', y: '2', width: '3', height: '12', fill: 'currentColor'}));
            svg.appendChild(svgEl('rect', {x: '9', y: '2', width: '3', height: '12', fill: 'currentColor'}));
        } else {
            svg.appendChild(svgEl('path', {d: 'M8 2l-4 4H1v4h3l4 4V2z'}));
            svg.appendChild(svgEl('path', {d: 'M11.5 8c0-1.1-.9-2-2-2v4c1.1 0 2-.9 2-2z'}));
            svg.appendChild(svgEl('path', {d: 'M13 8c0-2.2-1.8-4-4-4v2c1.1 0 2 .9 2 2s-.9 2-2 2v2c2.2 0 4-1.8 4-4z'}));
        }
        while (button.firstChild) {
            button.removeChild(button.firstChild);
        }
        button.appendChild(svg);
        button.setAttribute('aria-label', playing ? 'Pause audio' : 'Play audio');
    };

    /**
     * Stop current playback.
     */
    const stop = function() {
        if (speechSynthesis.speaking) {
            speechSynthesis.cancel();
        }
        if (currentPlayingButton) {
            currentPlayingButton.classList.remove('playing');
            updateButtonIcon(currentPlayingButton, 'play');
            currentPlayingButton = null;
        }
        currentUtterance = null;
    };

    /**
     * Handle play/pause toggle.
     *
     * @param {HTMLElement} button The play button
     * @param {string} text The text to speak
     */
    const handlePlayPause = function(button, text) {
        // If this button is already playing, pause it.
        if (currentPlayingButton === button && speechSynthesis.speaking) {
            if (speechSynthesis.paused) {
                speechSynthesis.resume();
                button.classList.add('playing');
            } else {
                speechSynthesis.pause();
                button.classList.remove('playing');
            }
            return;
        }

        // Stop any currently playing audio.
        stop();

        // Create and speak new utterance.
        currentUtterance = new SpeechSynthesisUtterance(text);
        currentUtterance.lang = document.documentElement.lang || 'en-US';
        currentUtterance.rate = 1.0;
        currentUtterance.pitch = 1.0;
        currentUtterance.volume = 1.0;

        currentUtterance.onstart = function() {
            button.classList.add('playing');
            currentPlayingButton = button;
            updateButtonIcon(button, 'pause');
        };

        currentUtterance.onend = function() {
            button.classList.remove('playing');
            currentPlayingButton = null;
            currentUtterance = null;
            updateButtonIcon(button, 'play');
        };

        currentUtterance.onerror = function() {
            button.classList.remove('playing');
            currentPlayingButton = null;
            currentUtterance = null;
            updateButtonIcon(button, 'play');
        };

        speechSynthesis.speak(currentUtterance);
    };

    /**
     * Initialize audio player for a message element.
     *
     * @param {HTMLElement} messageEl The message element
     */
    const initMessageAudio = function(messageEl) {
        const role = messageEl.getAttribute('data-role');
        if (role !== 'assistant') {
            return; // Only add audio to assistant messages.
        }

        if (!isSupported()) {
            return; // Browser doesn't support TTS.
        }

        // Check if button already exists.
        if (messageEl.querySelector('.local-ai-course-assistant__audio-btn')) {
            return;
        }

        const content = messageEl.querySelector('.local-ai-course-assistant__message-content');
        if (!content || !content.textContent.trim()) {
            return;
        }

        // Create audio button.
        const button = document.createElement('button');
        button.className = 'local-ai-course-assistant__audio-btn';
        button.setAttribute('aria-label', 'Play audio');
        // Render the initial play glyph from the template (CONTRIB-10574 #94).
        updateButtonIcon(button, 'play');

        button.addEventListener('click', function(e) {
            e.stopPropagation();
            handlePlayPause(button, content.textContent);
        });

        // Insert button at the start of the message.
        messageEl.insertBefore(button, content);
    };

    return {
        isSupported: isSupported,
        initMessageAudio: initMessageAudio,
        stop: stop
    };
});
