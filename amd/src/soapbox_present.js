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
 * Soapbox student presentation page controller (v6.8.25).
 *
 * For plain video/audio assignments it just wires the recorder. For slides
 * assignments it also orchestrates the deck flow: upload the PDF, render it to
 * page images, build the slide viewer, and hand the viewer + deck key to the
 * recorder (via the shared, by-reference config object) so advances are
 * captured with the recording. Recording is gated until a deck is ready.
 *
 * @module     local_ai_course_assistant/soapbox_present
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'local_ai_course_assistant/soapbox_recorder',
    'local_ai_course_assistant/soapbox_uploader',
    'local_ai_course_assistant/soapbox_slides',
    'core/ajax'
], function(Recorder, Uploader, Slides, Ajax) {

    return {
        /**
         * @param {object} config recorder config plus slidesEnabled, prevLabel, nextLabel
         * @param {object} sel selectors, incl. deckInput, deckStatus, slideViewer
         */
        init: function(config, sel) {
            var root = document.querySelector(sel.root);
            if (!root) {
                return;
            }

            // The recorder reads config.slides and config.deckKey at record /
            // upload time, so setting them later on this same object is enough.
            Recorder.init(config, sel);

            if (!config.slidesEnabled) {
                return;
            }

            var recBtn = root.querySelector(sel.record);
            var deckInput = root.querySelector(sel.deckInput);
            var viewerEl = root.querySelector(sel.slideViewer);
            var statusEl = root.querySelector(sel.deckStatus);
            var setStatus = function(msg) {
                if (statusEl) {
                    statusEl.textContent = msg;
                }
            };

            // Gate recording until a deck has been uploaded and processed.
            if (recBtn) {
                recBtn.disabled = true;
            }
            if (!deckInput) {
                return;
            }

            deckInput.addEventListener('change', function() {
                var file = deckInput.files && deckInput.files[0];
                if (!file) {
                    return;
                }
                if (file.type !== 'application/pdf' && !(/\.pdf$/i).test(file.name)) {
                    setStatus('Please choose a PDF file.');
                    return;
                }
                deckInput.disabled = true;
                setStatus('Uploading slides...');

                Uploader.uploadDeck({assignid: config.assignid, blob: file}).then(function(deckKey) {
                    config.deckKey = deckKey;
                    setStatus('Preparing slides...');
                    return Ajax.call([{
                        methodname: 'local_ai_course_assistant_soapbox_render_deck',
                        args: {assignid: config.assignid, deckkey: deckKey}
                    }])[0];
                }).then(function(res) {
                    if (res && res.pages && res.pages.length) {
                        if (viewerEl) {
                            viewerEl.innerHTML = '';
                        }
                        config.slides = Slides.create({
                            pages: res.pages,
                            container: viewerEl,
                            prevLabel: config.prevLabel,
                            nextLabel: config.nextLabel
                        });
                        setStatus(res.pages.length + ' slides ready. You can record now.');
                    } else {
                        // The deck is saved, but no preview (e.g. server rendering
                        // unavailable). Allow recording; no advance timeline.
                        setStatus('Slides uploaded (preview unavailable). You can record now.');
                    }
                    if (recBtn) {
                        recBtn.disabled = false;
                    }
                    deckInput.disabled = false;
                    return res;
                }).catch(function() {
                    setStatus('Could not process the slides. Please try again.');
                    deckInput.disabled = false;
                });
            });
        }
    };
});
