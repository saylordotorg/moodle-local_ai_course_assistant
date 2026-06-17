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
 * Browser shim for core/notification used by the standalone bundle.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Shim for Moodle's core/notification module (CDN bundle).
 *
 * SOLA's bundled modules use only Notification.exception() (in a couple of
 * rejected-Promise handlers, e.g. the learning-path panel fetch). Moodle's real
 * notification modal isn't available in CDN mode, so we surface the error to the
 * console instead. Keeping the same `exception` shape means the bundled modules
 * need no CDN-specific changes.
 */

function exception(error) {
    if (window.console && window.console.error) {
        window.console.error('SOLA:', error);
    }
}

export default {exception: exception};
export {exception};
