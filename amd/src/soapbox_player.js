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
 * Soapbox synced playback (v6.8.26): plays a saved recording with the slide
 * deck advancing alongside it, driven by the stored slide-advance timeline.
 * Scrubbing the video re-syncs the slide. The time->slide lookup is a pure
 * function so it is unit-testable without a browser.
 *
 * @module     local_ai_course_assistant/soapbox_player
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    /**
     * Slide index active at time t: the last timeline entry whose t <= the
     * playback time. Assumes the timeline is sorted by time (it is stored
     * normalized). Returns 0 before the first entry or for an empty timeline.
     *
     * @param {Array} timeline [{t, i}] sorted by t
     * @param {number} t seconds into playback
     * @return {number} slide index
     */
    var slideForTime = function(timeline, t) {
        var idx = 0;
        for (var k = 0; k < timeline.length; k++) {
            if (timeline[k].t <= t) {
                idx = timeline[k].i;
            } else {
                break;
            }
        }
        return idx;
    };

    return {
        // Exposed for unit testing.
        _slideForTime: slideForTime,

        /**
         * Bind "Play with slides" buttons (each carrying data-recid) to open
         * the synced player in the given container.
         *
         * @param {string} containerSel container selector
         * @param {string} btnSel play-button selector
         */
        init: function(containerSel, btnSel) {
            var container = document.querySelector(containerSel);
            if (!container) {
                return;
            }
            var self = this;
            document.querySelectorAll(btnSel).forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var recid = parseInt(btn.getAttribute('data-recid'), 10);
                    if (recid) {
                        container.textContent = '';
                        self.open(recid, container).catch(function() {
                            container.textContent = '';
                        });
                    }
                });
            });
        },

        /**
         * Open a synced player in a container by fetching playback data for a
         * recording and wiring the media element to the slide images.
         *
         * @param {number} recordingid
         * @param {HTMLElement} container
         * @param {object} [deps] optional {ajax} for testing
         * @return {Promise}
         */
        open: function(recordingid, container, deps) {
            deps = deps || {};
            var ajax = deps.ajax || Ajax;
            return ajax.call([{
                methodname: 'local_ai_course_assistant_soapbox_get_playback',
                args: {recordingid: recordingid}
            }])[0].then(function(data) {
                var timeline = [];
                try {
                    timeline = JSON.parse(data.timeline || '[]');
                } catch (e) {
                    timeline = [];
                }
                container.innerHTML = '';

                var media = document.createElement(data.mode === 'audio' ? 'audio' : 'video');
                media.controls = true;
                media.src = data.videourl;
                media.className = 'sbx-play-media w-100';
                if (data.mode !== 'audio') {
                    media.style.maxHeight = '360px';
                    media.style.background = '#000';
                }

                var wrap = document.createElement('div');
                wrap.className = 'sbx-play d-flex flex-wrap';
                wrap.style.gap = '12px';

                var mediaCol = document.createElement('div');
                mediaCol.style.flex = '1 1 320px';
                mediaCol.appendChild(media);
                wrap.appendChild(mediaCol);

                var slideImg = null;
                if (data.pages && data.pages.length) {
                    var slideCol = document.createElement('div');
                    slideCol.style.flex = '1 1 320px';
                    slideImg = document.createElement('img');
                    slideImg.className = 'sbx-play-slide w-100';
                    slideImg.alt = '';
                    slideImg.src = data.pages[0];
                    slideCol.appendChild(slideImg);
                    wrap.appendChild(slideCol);

                    var sync = function() {
                        var i = slideForTime(timeline, media.currentTime);
                        if (data.pages[i]) {
                            slideImg.src = data.pages[i];
                        }
                    };
                    media.addEventListener('timeupdate', sync);
                    media.addEventListener('seeked', sync);
                }

                container.appendChild(wrap);
                return data;
            });
        }
    };
});
