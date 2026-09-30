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

/**
 * Capability definitions for local_ai_course_assistant.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Use the AI tutor chat widget (students + academic support + administrators).
    'local/ai_course_assistant:use' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Use the assistant on pages that are not a course (dashboard, profile, site
    // home), against the administrator-designated support course.
    //
    // A SEPARATE capability at CONTEXT_SYSTEM, not an extra archetype on :use.
    // Archetypes apply at every context, so granting 'user' => CAP_ALLOW on :use
    // would switch the widget on in every course by inheritance and defeat the
    // per-course opt-out that default_course_mode provides.
    //
    // 'guest' is deliberately absent: Phase 1 is the authenticated case only.
    'local/ai_course_assistant:usesupport' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],
    // View usage and analytics dashboards (academic support + administrators).
    'local/ai_course_assistant:viewanalytics' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Manage plugin settings at course level — editing teachers, managers, and admins.
    // Controls: per-course AI provider overrides, off-topic limits, lockout durations.
    'local/ai_course_assistant:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'riskbitmask' => RISK_CONFIG,
    ],
];
