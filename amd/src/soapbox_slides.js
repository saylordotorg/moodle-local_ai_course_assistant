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
 * Soapbox slide viewer and advance-capture (v6.8.23).
 *
 * Renders a simple deck viewer (one page image at a time) with next/prev via
 * buttons, arrow keys, and touch swipe, and records a timeline of slide
 * advances while recording is active. The timeline logic is a pure inner
 * factory (makeTimeline) so it is unit-testable without a browser; the viewer
 * wires the DOM to it. Page images are produced elsewhere (a PDF renderer) and
 * passed in as `pages`.
 *
 * @module     local_ai_course_assistant/soapbox_slides
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /**
     * Pure slide-advance timeline. Records {t, i} entries while active.
     *
     * @param {function} getElapsed () => seconds into the recording
     * @return {object} timeline controller
     */
    var makeTimeline = function(getElapsed) {
        var events = [];
        var active = false;
        var clock = getElapsed;
        return {
            start: function(startIndex, getElapsedOverride) {
                if (typeof getElapsedOverride === 'function') {
                    clock = getElapsedOverride;
                }
                events = [{t: 0, i: startIndex || 0}];
                active = true;
            },
            stop: function() {
                active = false;
            },
            record: function(index) {
                if (!active) {
                    return;
                }
                var t = Math.max(0, Math.round(clock()));
                var last = events[events.length - 1];
                // Collapse a same-second advance into the latest index so a rapid
                // double-tap does not create two entries at the same timestamp.
                if (last && last.t === t) {
                    last.i = index;
                } else {
                    events.push({t: t, i: index});
                }
            },
            get: function() {
                return events.slice();
            },
            isActive: function() {
                return active;
            }
        };
    };

    return {
        // Exposed for unit testing.
        _makeTimeline: makeTimeline,

        /**
         * Create a slide viewer bound to a container.
         *
         * @param {object} opts
         *   pages {string[]} page image URLs
         *   container {HTMLElement} where to render
         *   getElapsed {function} () => seconds into the recording (for the timeline)
         *   prevLabel {string} previous-button label
         *   nextLabel {string} next-button label
         * @return {object} controller { next, prev, goTo, current, count,
         *                               startCapture, stopCapture, getTimeline, destroy }
         */
        create: function(opts) {
            var pages = opts.pages || [];
            var container = opts.container;
            var timeline = makeTimeline(opts.getElapsed || function() {
                return 0;
            });
            var index = 0;

            var img = document.createElement('img');
            img.className = 'sbx-slide-img';
            img.alt = '';
            img.style.maxWidth = '100%';
            var counter = document.createElement('span');
            counter.className = 'sbx-slide-counter';
            var prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'sbx-slide-prev btn btn-outline-secondary btn-sm';
            prev.textContent = opts.prevLabel || 'Previous';
            var next = document.createElement('button');
            next.type = 'button';
            next.className = 'sbx-slide-next btn btn-outline-secondary btn-sm';
            next.textContent = opts.nextLabel || 'Next';

            var controls = document.createElement('div');
            controls.className = 'sbx-slide-controls d-flex align-items-center';
            controls.style.gap = '10px';
            controls.appendChild(prev);
            controls.appendChild(counter);
            controls.appendChild(next);

            var render = function() {
                if (pages.length) {
                    img.src = pages[index];
                }
                counter.textContent = (index + 1) + ' / ' + pages.length;
                prev.disabled = (index <= 0);
                next.disabled = (index >= pages.length - 1);
            };

            var goTo = function(i) {
                var clamped = Math.max(0, Math.min(pages.length - 1, i));
                if (clamped === index) {
                    return;
                }
                index = clamped;
                render();
                timeline.record(index);
            };
            var goNext = function() {
                goTo(index + 1);
            };
            var goPrev = function() {
                goTo(index - 1);
            };

            next.addEventListener('click', goNext);
            prev.addEventListener('click', goPrev);

            var onKey = function(e) {
                if (e.key === 'ArrowRight' || e.key === 'PageDown') {
                    goNext();
                } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
                    goPrev();
                }
            };
            container.setAttribute('tabindex', '0');
            container.addEventListener('keydown', onKey);

            // Touch swipe.
            var touchX = null;
            container.addEventListener('touchstart', function(e) {
                touchX = e.changedTouches[0].clientX;
            });
            container.addEventListener('touchend', function(e) {
                if (touchX === null) {
                    return;
                }
                var dx = e.changedTouches[0].clientX - touchX;
                if (Math.abs(dx) > 40) {
                    if (dx < 0) {
                        goNext();
                    } else {
                        goPrev();
                    }
                }
                touchX = null;
            });

            if (container) {
                container.appendChild(img);
                container.appendChild(controls);
                render();
            }

            return {
                next: goNext,
                prev: goPrev,
                goTo: goTo,
                current: function() {
                    return index;
                },
                count: function() {
                    return pages.length;
                },
                startCapture: function(getElapsedOverride) {
                    timeline.start(index, getElapsedOverride);
                },
                stopCapture: function() {
                    timeline.stop();
                },
                getTimeline: function() {
                    return timeline.get();
                },
                destroy: function() {
                    container.removeEventListener('keydown', onKey);
                    if (container.contains(img)) {
                        container.removeChild(img);
                    }
                    if (container.contains(controls)) {
                        container.removeChild(controls);
                    }
                }
            };
        }
    };
});
