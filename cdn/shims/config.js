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
 * Browser shim for core/config used by the standalone bundle.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Shim for Moodle's core/config module.
 *
 * Moodle's core/config exposes site-wide config values (sesskey, wwwroot,
 * etc.) populated into the global `M.cfg` object on every Moodle page.
 * The shim re-exports those values so SOLA AMD modules that
 * `define(['core/config'], ...)` resolve correctly under the CDN mini AMD
 * loader. Used by amd/src/repository.js for Config.sesskey + Config.wwwroot
 * when building file-upload URLs.
 */

const cfg = (window.M && window.M.cfg) ? window.M.cfg : {};

export default cfg;
export {cfg};
