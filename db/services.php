<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Web service definitions for External Sync.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_extsync_push_lesson' => [
        'classname' => 'local_extsync\external',
        'methodname' => 'push_lesson',
        'description' => 'Create or update one lesson in a course: section, assignment, materials, pages and quizzes.',
        'type' => 'write',
        'capabilities' => 'moodle/course:manageactivities, moodle/course:update, mod/assign:addinstance, '
            . 'mod/resource:addinstance, mod/page:addinstance, mod/quiz:addinstance, mod/quiz:manage, moodle/question:add, '
            . 'mod/subsection:addinstance',
        'ajax' => false,
    ],
    'local_extsync_fetch_grades' => [
        'classname' => 'local_extsync\external',
        'methodname' => 'fetch_grades',
        'description' => 'Read finished quiz attempts question by question, keyed by the ID number of each question.',
        'type' => 'read',
        'capabilities' => 'mod/quiz:viewreports, moodle/site:viewuseridentity, moodle/course:viewhiddenactivities, '
            . 'moodle/site:accessallgroups',
        'ajax' => false,
    ],
    'local_extsync_push_exam' => [
        'classname' => 'local_extsync\external',
        'methodname' => 'push_exam',
        'description' => 'Create a quiz-only exam, or replace the questions of an exam nobody has attempted yet.',
        'type' => 'write',
        'capabilities' => 'moodle/course:manageactivities, moodle/course:update, mod/quiz:addinstance, '
            . 'mod/quiz:manage, moodle/question:add',
        'ajax' => false,
    ],
    'local_extsync_sync_students' => [
        'classname' => 'local_extsync\external',
        'methodname' => 'sync_students',
        'description' => 'Enrol the listed existing users through manual enrolment, and optionally unenrol students not listed.',
        'type' => 'write',
        'capabilities' => 'enrol/manual:enrol, enrol/manual:unenrol, moodle/course:enrolconfig',
        'ajax' => false,
    ],
];

// This is a new component, so Moodle creates this service fresh and it holds no tokens yet. The
// administrator issues a token against it; a token issued to an earlier component's service is not
// carried over and does not authorise these functions.
$services = [
    'External Sync push' => [
        'functions' => [
            'local_extsync_push_lesson',
            'local_extsync_fetch_grades',
            'local_extsync_push_exam',
            'local_extsync_sync_students',
            // The external system's course and section pickers.
            'core_course_search_courses',
            'core_course_get_contents',
        ],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'extsync',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
