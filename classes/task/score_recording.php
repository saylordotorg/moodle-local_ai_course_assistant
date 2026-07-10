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

namespace local_ai_course_assistant\task;

/**
 * Ad-hoc task: transcribe and score one Soapbox recording (v6.8.16). Queued by
 * finalize_recording so scoring happens off the request, and retried by the
 * task runner if transcription or scoring is transiently unavailable.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class score_recording extends \core\task\adhoc_task {

    /**
     * Score the recording named in the custom data.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $recid = (int) ($data->recid ?? 0);
        if ($recid > 0) {
            \local_ai_course_assistant\soapbox_scorer::score_recording($recid);
        }
    }
}
