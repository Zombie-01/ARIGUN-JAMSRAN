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

namespace local_extsync;

use context_course;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use local_extsync\local\materials;
use local_extsync\local\modules;
use local_extsync\local\ownership;
use local_extsync\local\question_import;
use moodle_exception;
use stdClass;

/**
 * Web service functions of External Sync.
 *
 * Every function validates its parameters and the course context, and checks the capabilities
 * the Moodle user interface requires for the same action before anything is changed. Module ids
 * sent by the external system are only honoured for modules this plugin created ({@see ownership}).
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external extends external_api {
    /** @var int Students accepted per sync_students request: 3 input variables each stays below max_input_vars 5000. */
    public const MAX_STUDENTS = 1000;

    /** @var float Largest share of a class sync_students may remove within REMOVAL_PERIOD. */
    public const MAX_REMOVAL_RATIO = 0.5;

    /** @var int Seconds over which sync_students removals add up. */
    public const REMOVAL_PERIOD = 7 * 24 * 3600;

    /** @var int Seconds a request waits for another one on the same course (sync) or exam (replacement). */
    public const LOCK_TIMEOUT = 5;

    /**
     * Parameters of push_lesson.
     *
     * The order is part of the contract: Moodle passes the values to push_lesson() positionally.
     *
     * @return external_function_parameters
     */
    public static function push_lesson_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Target course id'),
            'sectionid' => new external_value(PARAM_INT, 'Existing course_sections.id, 0 to create', VALUE_DEFAULT, 0),
            // With a unit name the lesson goes into a collapsible subsection of that unit's section.
            'unitname' => new external_value(PARAM_TEXT, 'Unit name', VALUE_DEFAULT, ''),
            'unitsectionid' => new external_value(PARAM_INT, 'Existing unit section id', VALUE_DEFAULT, 0),
            'subsectioncmid' => new external_value(PARAM_INT, 'Existing subsection cmid', VALUE_DEFAULT, 0),
            'renamesection' => new external_value(
                PARAM_BOOL,
                'Overwrite the section name and summary. 0 when the teacher picked an existing section.',
                VALUE_DEFAULT,
                true
            ),
            'assigncmid' => new external_value(PARAM_INT, 'Existing assignment cmid, 0 to create', VALUE_DEFAULT, 0),
            'filecmids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'cmid'),
                'Resource cmids from the previous push',
                VALUE_DEFAULT,
                []
            ),
            'name' => new external_value(PARAM_TEXT, 'Lesson topic, used as the section name'),
            'assignname' => new external_value(
                PARAM_TEXT,
                'Assignment name. Empty falls back to the lesson topic.',
                VALUE_DEFAULT,
                ''
            ),
            'summary' => new external_value(PARAM_RAW, 'Section summary, HTML', VALUE_DEFAULT, ''),
            'assignintro' => new external_value(PARAM_RAW, 'Assignment description, HTML', VALUE_DEFAULT, ''),
            'files' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_FILE, 'File name'),
                'url' => new external_value(PARAM_URL, 'HTTPS URL on an allowed material host'),
                'description' => new external_value(PARAM_TEXT, 'Activity name', VALUE_DEFAULT, ''),
            ]), 'Lesson materials', VALUE_DEFAULT, []),
            'quizcmid' => new external_value(PARAM_INT, 'Existing quiz cmid, 0 to create', VALUE_DEFAULT, 0),
            'quizname' => new external_value(PARAM_TEXT, 'Quiz name', VALUE_DEFAULT, ''),
            'quizxml' => new external_value(PARAM_RAW, 'Questions in Moodle XML, empty for no quiz', VALUE_DEFAULT, ''),
            // Lesson pages. The external system decides which pages exist and in what order.
            'pages' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Stable key, matches the page across pushes'),
                'name' => new external_value(PARAM_TEXT, 'Module name'),
                'html' => new external_value(PARAM_RAW, 'Page body, HTML'),
                'cmid' => new external_value(PARAM_INT, 'Existing cmid, 0 to create', VALUE_DEFAULT, 0),
            ]), 'Lesson pages in display order', VALUE_DEFAULT, []),
            // One extra quiz per difficulty level; quizcmid and quizxml remain for single-quiz callers.
            'quizzes' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Stable key, matches the quiz across pushes'),
                'name' => new external_value(PARAM_TEXT, 'Quiz name'),
                'xml' => new external_value(PARAM_RAW, 'Questions in Moodle XML'),
                'cmid' => new external_value(PARAM_INT, 'Existing cmid, 0 to create', VALUE_DEFAULT, 0),
            ]), 'Level quizzes in display order', VALUE_DEFAULT, []),
            // Tokens such as assign, files, page:leadin, quiz:level1. Anything omitted keeps its place.
            'ordering' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Order token, e.g. page:leadin'),
                'Module order inside the section',
                VALUE_DEFAULT,
                []
            ),
            // Modules the external system no longer produces. Only modules created by this plugin are deleted.
            'deletecmids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'cmid'),
                'Stale modules to remove',
                VALUE_DEFAULT,
                []
            ),
            // Last on purpose: the argument order must stay backward compatible.
            'makeassign' => new external_value(
                PARAM_BOOL,
                'Create/update the assignment. 0 for a quiz-only push.',
                VALUE_DEFAULT,
                1
            ),
        ]);
    }

    /**
     * Return structure of push_lesson.
     *
     * @return external_single_structure
     */
    public static function push_lesson_returns(): external_single_structure {
        return new external_single_structure([
            'sectionid' => new external_value(PARAM_INT, 'course_sections.id'),
            'unitsectionid' => new external_value(PARAM_INT, 'Unit section id, 0 when not used'),
            'subsectioncmid' => new external_value(PARAM_INT, 'Subsection cmid, 0 when not used'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number'),
            'assigncmid' => new external_value(PARAM_INT, 'Assignment cmid'),
            'filecmids' => new external_multiple_structure(new external_value(PARAM_INT, 'cmid')),
            'quizcmid' => new external_value(PARAM_INT, 'Quiz cmid, 0 if no quiz'),
            'pagecmids' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Page key'),
                'cmid' => new external_value(PARAM_INT, 'cmid'),
            ]), 'Page cmids, keyed so the next push updates instead of duplicating'),
            'quizcmids' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Quiz key'),
                'cmid' => new external_value(PARAM_INT, 'cmid'),
                'questions' => new external_value(PARAM_INT, 'Questions imported into it'),
            ]), 'Level quiz cmids'),
            'questions' => new external_value(PARAM_INT, 'Questions imported by this call'),
            'error' => new external_value(PARAM_TEXT, 'Errors of the steps that failed, empty when all succeeded'),
            'courseurl' => new external_value(PARAM_URL, 'Course page URL'),
            'warnings' => new external_warnings(),
        ]);
    }

    /**
     * Create or update one lesson in a course.
     *
     * Idempotent through the ids the caller stores: pass them back and the same modules are
     * reused. Materials are downloaded before anything changes, and old material activities are
     * only removed after all new ones exist.
     *
     * @param int $courseid course id
     * @param int $sectionid existing section id or 0
     * @param string $unitname unit name
     * @param int $unitsectionid existing unit section id
     * @param int $subsectioncmid existing subsection cmid
     * @param bool $renamesection overwrite the section name and summary
     * @param int $assigncmid existing assignment cmid
     * @param int[] $filecmids resource cmids from the previous push
     * @param string $name lesson topic
     * @param string $assignname assignment name
     * @param string $summary section summary
     * @param string $assignintro assignment description
     * @param array $files materials
     * @param int $quizcmid existing quiz cmid
     * @param string $quizname quiz name
     * @param string $quizxml quiz questions
     * @param array $pages lesson pages
     * @param array $quizzes level quizzes
     * @param string[] $ordering display order tokens
     * @param int[] $deletecmids stale modules
     * @param bool $makeassign create or update the assignment
     * @return array
     */
    public static function push_lesson(
        $courseid,
        $sectionid,
        $unitname,
        $unitsectionid,
        $subsectioncmid,
        $renamesection,
        $assigncmid,
        $filecmids,
        $name,
        $assignname,
        $summary,
        $assignintro,
        $files,
        $quizcmid = 0,
        $quizname = '',
        $quizxml = '',
        $pages = [],
        $quizzes = [],
        $ordering = [],
        $deletecmids = [],
        $makeassign = 1
    ) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $params = self::validate_parameters(self::push_lesson_parameters(), [
            'courseid' => $courseid, 'sectionid' => $sectionid,
            'unitname' => $unitname, 'unitsectionid' => $unitsectionid, 'subsectioncmid' => $subsectioncmid,
            'renamesection' => $renamesection, 'assigncmid' => $assigncmid, 'filecmids' => $filecmids,
            'name' => $name, 'assignname' => $assignname, 'summary' => $summary, 'assignintro' => $assignintro,
            'files' => $files, 'quizcmid' => $quizcmid, 'quizname' => $quizname, 'quizxml' => $quizxml,
            'pages' => $pages, 'quizzes' => $quizzes, 'ordering' => $ordering,
            'deletecmids' => $deletecmids, 'makeassign' => $makeassign,
        ]);

        $course = get_course($params['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        $warnings = [];
        $errors = [];

        // 1. Resolve what already exists. Ids of modules this plugin did not create are ignored.
        $section = $params['sectionid']
            ? $DB->get_record('course_sections', ['id' => $params['sectionid'], 'course' => $course->id])
            : false;
        $assigncm = $params['makeassign'] ? self::owned($course, $params['assigncmid'], 'assign', $warnings) : null;
        $quizcm = self::owned($course, $params['quizcmid'], 'quiz', $warnings);
        $oldfiles = [];
        foreach ($params['filecmids'] as $cmid) {
            if ($cm = self::owned($course, $cmid, 'resource', $warnings)) {
                $oldfiles[$cm->id] = $cm;
            }
        }
        $pagecms = [];
        foreach ($params['pages'] as $page) {
            $pagecms[$page['key']] = self::owned($course, $page['cmid'], 'page', $warnings);
        }
        $levelcms = [];
        foreach ($params['quizzes'] as $quiz) {
            $levelcms[$quiz['key']] = self::owned($course, $quiz['cmid'], 'quiz', $warnings);
        }
        $stale = [];
        foreach ($params['deletecmids'] as $cmid) {
            if ($cm = self::owned($course, $cmid, null, $warnings)) {
                $stale[$cm->id] = $cm;
            }
        }

        // 2. Check every capability the actions below need, before anything changes.
        // A missing section is created (possibly with a unit section); an existing one is renamed or
        // gets an empty summary filled in.
        $editsection = !$section || $params['renamesection'] || self::summary_is_empty($section->summary ?? '');
        if ($editsection) {
            require_capability('moodle/course:update', $context);
        }
        if ($params['makeassign'] && !$assigncm) {
            require_capability('mod/assign:addinstance', $context);
            // Checked now: create_module() would refuse a disabled module only after the section exists.
            if (!\core\plugininfo\mod::get_enabled_plugin('assign') || !course_allowed_module($course, 'assign')) {
                throw new moodle_exception('moduledisable', 'error', '', 'assign');
            }
        }
        if ($params['files']) {
            require_capability('mod/resource:addinstance', $context);
        }
        foreach ($params['pages'] as $page) {
            if (trim($page['html']) !== '' && empty($pagecms[$page['key']])) {
                require_capability('mod/page:addinstance', $context);
            }
        }
        $newquiz = !$quizcm && trim($params['quizxml']) !== '';
        foreach ($params['quizzes'] as $quiz) {
            $newquiz = $newquiz || (empty($levelcms[$quiz['key']]) && trim($quiz['xml']) !== '');
        }
        if ($newquiz) {
            require_capability('mod/quiz:addinstance', $context);
            require_capability('mod/quiz:manage', $context);
            require_capability('moodle/question:add', $context);
        }
        $existing = array_filter(array_merge([$assigncm, $quizcm], $oldfiles, $pagecms, $levelcms, $stale));
        foreach ($existing as $cm) {
            require_capability('moodle/course:manageactivities', context_module::instance($cm->id));
        }

        // 3. Download the materials. Nothing has changed yet, so a failure leaves the course as it was.
        $downloaded = null;
        try {
            $downloaded = \core\di::get(materials::class)->download_all($params['files']);
        } catch (moodle_exception $e) {
            $warnings[] = self::warning(
                'files',
                0,
                'materialsnotreplaced',
                get_string('materialsnotreplaced', 'local_extsync', $e->getMessage())
            );
        }

        // 4. Section, or a subsection of the unit's section.
        $unitsectionid = 0;
        $subsectionid = 0;
        $created = false;
        if (!$section && $params['unitname'] !== '') {
            try {
                [$section, $unitsectionid, $subsectionid, $created] = self::subsection_for($course, $params, $warnings);
            } catch (\Throwable $e) {
                // A regular section is better than no lesson at all.
                $section = false;
                $unitsectionid = 0;
                $subsectionid = 0;
                $errors[] = get_string('subsectionfailed', 'local_extsync', $e->getMessage());
            }
        }
        if (!$section) {
            $section = course_create_section($course->id);
            $created = true;
        }
        $summaryhtml = clean_text($params['summary'], FORMAT_HTML);
        if ($created || $params['renamesection']) {
            course_update_section($course, $section, [
                'name' => $params['name'],
                'summary' => $summaryhtml,
                'summaryformat' => FORMAT_HTML,
            ]);
        } else if (self::summary_is_empty($section->summary ?? '')) {
            // A section the teacher picked keeps its name; only an empty description is filled in.
            course_update_section($course, $section, ['summary' => $summaryhtml, 'summaryformat' => FORMAT_HTML]);
        }
        $sectionnum = (int)$section->section;

        // 5. Assignment. Updating keeps every setting the teacher chose; only name and description change.
        $assignid = 0;
        if ($params['makeassign']) {
            $title = $params['assignname'] !== '' ? $params['assignname'] : $params['name'];
            $intro = clean_text($params['assignintro'], FORMAT_HTML);
            if ($assigncm) {
                modules::update_assign($assigncm, $course, $title, $intro);
                modules::move_to_section($course, $assigncm, $section);
                $assignid = (int)$assigncm->id;
            } else {
                $assignid = modules::create(modules::assign_data($course, $sectionnum, $title, $intro));
            }
        }

        // 6. Materials: create all new resources first, only then remove the old ones.
        $newfileids = array_map('intval', array_keys($oldfiles));
        if ($downloaded !== null) {
            $newresources = [];
            try {
                foreach ($downloaded as $item) {
                    $newresources[] = self::create_resource($course, $sectionnum, $item['file'], $item['path']);
                }
            } catch (\Throwable $e) {
                // An incomplete new set is removed again; the old materials stay in place.
                foreach ($newresources as $cmid) {
                    modules::delete($course, $cmid);
                }
                $newresources = null;
                $warnings[] = self::warning(
                    'files',
                    0,
                    'materialsnotreplaced',
                    get_string('materialsnotreplaced', 'local_extsync', $e->getMessage())
                );
            }
            if ($newresources !== null) {
                // The complete new set exists; an old activity that cannot be removed is kept and reported.
                $newfileids = $newresources;
                foreach ($oldfiles as $cm) {
                    try {
                        modules::delete($course, (int)$cm->id);
                    } catch (\Throwable $e) {
                        $newfileids[] = (int)$cm->id;
                        $warnings[] = self::warning(
                            'module',
                            (int)$cm->id,
                            'modulenotdeleted',
                            get_string('modulenotdeleted', 'local_extsync', $cm->id)
                        );
                    }
                }
            }
        }

        // 7. Single quiz. An existing quiz is never rebuilt: it may hold attempts.
        $questions = 0;
        $quizid = 0;
        if ($quizcm) {
            $quizid = (int)$quizcm->id;
        } else if (trim($params['quizxml']) !== '') {
            try {
                [$quizid, $questions] = self::create_quiz(
                    $course,
                    $sectionnum,
                    $params['quizname'] !== '' ? $params['quizname'] : $params['name'],
                    $params['quizxml']
                );
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        // 8. Lesson pages. A page holds no student work, so its content follows the external system.
        $pagecmids = [];
        foreach ($params['pages'] as $page) {
            if (trim($page['html']) === '') {
                continue;
            }
            $html = clean_text($page['html'], FORMAT_HTML);
            try {
                $cm = $pagecms[$page['key']] ?? null;
                if ($cm) {
                    modules::update_page($cm, $course, $page['name'], $html);
                    modules::move_to_section($course, $cm, $section);
                    $cmid = (int)$cm->id;
                } else {
                    $cmid = modules::create(modules::page_data($course, $sectionnum, $page['name'], $html));
                }
                $pagecmids[] = ['key' => $page['key'], 'cmid' => $cmid];
            } catch (\Throwable $e) {
                $errors[] = $page['name'] . ': ' . $e->getMessage();
            }
        }

        // 9. Level quizzes, same rule as the single quiz.
        $quizcmids = [];
        foreach ($params['quizzes'] as $quiz) {
            $cm = $levelcms[$quiz['key']] ?? null;
            $cmid = 0;
            $count = 0;
            if ($cm) {
                modules::move_to_section($course, $cm, $section);
                $cmid = (int)$cm->id;
            } else if (trim($quiz['xml']) !== '') {
                try {
                    [$cmid, $count] = self::create_quiz($course, $sectionnum, $quiz['name'], $quiz['xml']);
                } catch (\Throwable $e) {
                    $errors[] = $quiz['name'] . ': ' . $e->getMessage();
                }
            }
            $quizcmids[] = ['key' => $quiz['key'], 'cmid' => $cmid, 'questions' => $count];
        }

        // 10. Stale modules, except anything this push still uses or that holds student work.
        $inuse = array_merge(
            [$assignid, $quizid, $subsectionid],
            $newfileids,
            array_column($pagecmids, 'cmid'),
            array_column($quizcmids, 'cmid')
        );
        // The subsection whose section holds this lesson is in use, also when the caller sent that section's id.
        if (($section->component ?? '') === 'mod_subsection') {
            $holder = get_coursemodule_from_instance('subsection', $section->itemid, $course->id);
            if ($holder) {
                $inuse[] = (int)$holder->id;
            }
        }
        foreach ($stale as $cm) {
            if (in_array((int)$cm->id, $inuse, true) || !$DB->record_exists('course_modules', ['id' => $cm->id])) {
                continue;
            }
            if ($reason = modules::deletion_blocker($cm)) {
                $warnings[] = self::warning('module', (int)$cm->id, $reason, get_string($reason, 'local_extsync', $cm->id));
                continue;
            }
            try {
                modules::delete($course, (int)$cm->id);
            } catch (\Throwable $e) {
                // The lesson is already pushed: report the module instead of losing the returned ids.
                $warnings[] = self::warning(
                    'module',
                    (int)$cm->id,
                    'modulenotdeleted',
                    get_string('modulenotdeleted', 'local_extsync', $cm->id)
                );
            }
        }

        // 11. Display order: appending each module in the wanted order leaves the section in that order.
        if ($params['ordering']) {
            $bykey = ['assign' => $assignid ? [$assignid] : [], 'files' => $newfileids];
            foreach ($pagecmids as $row) {
                $bykey['page:' . $row['key']] = [$row['cmid']];
            }
            foreach ($quizcmids as $row) {
                $bykey['quiz:' . $row['key']] = [$row['cmid']];
            }
            if ($quizid) {
                $bykey['quiz'] = [$quizid];
            }
            foreach ($params['ordering'] as $token) {
                foreach ($bykey[$token] ?? [] as $cmid) {
                    if ($cmid && $DB->record_exists('course_modules', ['id' => $cmid, 'course' => $course->id])) {
                        modules::append_to_section($course, (int)$cmid, $section);
                    }
                }
            }
        }

        // An existing quiz is not rebuilt, but it follows the lesson to a different section.
        if ($quizcm) {
            modules::move_to_section($course, $quizcm, $section);
        }

        rebuild_course_cache($course->id);

        return [
            'sectionid' => (int)$section->id,
            'unitsectionid' => (int)$unitsectionid,
            'subsectioncmid' => (int)$subsectionid,
            'sectionnum' => $sectionnum,
            'assigncmid' => $assignid,
            'filecmids' => $newfileids,
            'quizcmid' => $quizid,
            'pagecmids' => $pagecmids,
            'quizcmids' => $quizcmids,
            'questions' => $questions,
            'error' => implode(' ', $errors),
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'warnings' => $warnings,
        ];
    }

    /**
     * Parameters of fetch_grades.
     *
     * @return external_function_parameters
     */
    public static function fetch_grades_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'quizcmid' => new external_value(PARAM_INT, 'Quiz cmid'),
        ]);
    }

    /**
     * Return structure of fetch_grades.
     *
     * @return external_multiple_structure
     */
    public static function fetch_grades_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'moodleuserid' => new external_value(PARAM_INT, 'Moodle user id'),
            'idnumber' => new external_value(PARAM_RAW, 'User ID number, empty when not visible to the caller'),
            'username' => new external_value(PARAM_RAW, 'Username, empty when not visible to the caller'),
            'email' => new external_value(PARAM_RAW, 'Email, empty when not visible to the caller'),
            'timefinish' => new external_value(PARAM_INT, 'Attempt finish time'),
            'grade' => new external_value(
                PARAM_FLOAT,
                'Attempt grade as a percentage, null while a question still needs grading',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'questions' => new external_multiple_structure(new external_single_structure([
                'ref' => new external_value(PARAM_RAW, 'Question idnumber set by the external system'),
                'fraction' => new external_value(
                    PARAM_FLOAT,
                    'Score 0..1, null when not graded yet',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
                'mark' => new external_value(PARAM_FLOAT, 'Mark', VALUE_REQUIRED, null, NULL_ALLOWED),
                'maxmark' => new external_value(PARAM_FLOAT, 'Maximum mark'),
            ])),
        ]));
    }

    /**
     * Read the finished quiz attempts, question by question.
     *
     * Only the last finished attempt of each student is reported. Identity fields are only
     * returned when the caller may see them in the quiz context ($CFG->showuseridentity and
     * moodle/site:viewuseridentity), and separate groups are respected, as in the quiz reports.
     *
     * @param int $courseid course id
     * @param int $quizcmid quiz cmid
     * @return array
     */
    public static function fetch_grades($courseid, $quizcmid) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $params = self::validate_parameters(
            self::fetch_grades_parameters(),
            ['courseid' => $courseid, 'quizcmid' => $quizcmid]
        );

        $course = get_course($params['courseid']);
        $cm = get_coursemodule_from_id('quiz', $params['quizcmid'], $course->id, false, MUST_EXIST);
        $modcontext = context_module::instance($cm->id);
        // The quiz context, as the quiz reports: a hidden or restricted quiz needs the right to see it.
        self::validate_context($modcontext);
        require_capability('mod/quiz:viewreports', $modcontext);

        $identity = \core_user\fields::get_identity_fields($modcontext, false);

        $allowed = null;
        if (
            groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $modcontext)
        ) {
            $allowed = [];
            foreach (groups_get_all_groups($course->id, $USER->id, $cm->groupingid) as $group) {
                foreach (groups_get_members($group->id, 'u.id') as $member) {
                    $allowed[(int)$member->id] = true;
                }
            }
        }

        // Current participants only, as in the quiz reports: not users who were unenrolled or deleted.
        [$participantsql, $participantparams] = get_enrolled_sql(
            $modcontext,
            ['mod/quiz:attempt', 'mod/quiz:reviewmyattempts']
        );
        $attempts = $DB->get_records_select(
            'quiz_attempts',
            "quiz = :quizid AND state = :state AND preview = 0 AND userid IN ($participantsql)",
            ['quizid' => $cm->instance, 'state' => \mod_quiz\quiz_attempt::FINISHED] + $participantparams,
            'userid ASC, timefinish ASC, id ASC'
        );

        // Later attempts overwrite earlier ones for the same student.
        $latest = [];
        foreach ($attempts as $attempt) {
            if ($allowed === null || isset($allowed[(int)$attempt->userid])) {
                $latest[(int)$attempt->userid] = $attempt;
            }
        }
        if (!$latest) {
            return [];
        }

        $users = $DB->get_records_list('user', 'id', array_keys($latest), '', 'id, idnumber, username, email');

        $out = [];
        foreach ($latest as $userid => $attempt) {
            $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
            $refs = self::question_refs($attemptobj, (int)$cm->id);
            $questions = [];
            foreach ($attemptobj->get_slots() as $slot) {
                $qa = $attemptobj->get_question_attempt($slot);
                $ref = $refs[$qa->get_question_id()] ?? '';
                if ($ref === '') {
                    continue; // Not an external question: a teacher may have added it.
                }
                $questions[] = [
                    'ref' => $ref,
                    'fraction' => $qa->get_fraction(),
                    'mark' => $qa->get_mark(),
                    'maxmark' => (float)$qa->get_max_mark(),
                ];
            }

            $user = $users[$userid] ?? null;
            $sumgrades = $attemptobj->get_quiz()->sumgrades;
            $out[] = [
                'moodleuserid' => $userid,
                'idnumber' => $user && in_array('idnumber', $identity) ? (string)$user->idnumber : '',
                'username' => $user && in_array('username', $identity) ? (string)$user->username : '',
                'email' => $user && in_array('email', $identity) ? (string)$user->email : '',
                'timefinish' => (int)$attempt->timefinish,
                // Null, not 0, while a question (for example an essay) still needs grading.
                'grade' => $attempt->sumgrades === null ? null
                    : ($sumgrades > 0 ? round(100 * $attempt->sumgrades / $sumgrades, 4) : 0.0),
                'questions' => $questions,
            ];
        }

        return $out;
    }

    /**
     * Parameters of sync_students.
     *
     * @return external_function_parameters
     */
    public static function sync_students_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Target course id'),
            'students' => new external_multiple_structure(new external_single_structure([
                // At least one key must be set. Matching order: idnumber, username, email.
                'idnumber' => new external_value(PARAM_RAW, 'Student ID number', VALUE_DEFAULT, ''),
                'username' => new external_value(PARAM_RAW, 'Moodle username', VALUE_DEFAULT, ''),
                'email' => new external_value(PARAM_RAW, 'Email', VALUE_DEFAULT, ''),
            ]), 'Students who should be enrolled, at most ' . self::MAX_STUDENTS, VALUE_DEFAULT, []),
            'removemissing' => new external_value(
                PARAM_BOOL,
                'Unenrol manually enrolled students who are not in the list',
                VALUE_DEFAULT,
                false
            ),
            'expectedcount' => new external_value(
                PARAM_INT,
                'Number of students sent, to detect a truncated request. Required.',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    /**
     * Return structure of sync_students.
     *
     * @return external_single_structure
     */
    public static function sync_students_returns(): external_single_structure {
        return new external_single_structure([
            'enrolled' => new external_value(PARAM_INT, 'Newly enrolled'),
            'already' => new external_value(PARAM_INT, 'Already enrolled, left alone'),
            'unenrolled' => new external_value(PARAM_INT, 'Removed from the course'),
            'unmatched' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Key that matched no Moodle user'),
                'Students with no Moodle account'
            ),
            'ambiguous' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Key that matched more than one Moodle user'),
                'Students left unchanged because their key is not unique'
            ),
        ]);
    }

    /**
     * Align the manual enrolments of a course with the external system's student list.
     *
     * Users are never created: a student without an account is reported in unmatched. A key that
     * matches several accounts is reported in ambiguous and none of those accounts is changed.
     * Only the manual enrolment method is touched; cohort, self and other enrolments belong to the
     * school's own processes. Every request must state expectedcount, so a truncated list is refused.
     * Removal is refused for an empty list, and when the students removed within REMOVAL_PERIOD,
     * this request included, would exceed half of the class as it was at the start of that period.
     * Users holding any other role in the course are never unenrolled.
     *
     * @param int $courseid course id
     * @param array $students students with idnumber, username and email
     * @param bool $removemissing unenrol students not in the list
     * @param int $expectedcount number of students the caller sent
     * @return array
     */
    public static function sync_students($courseid, $students = [], $removemissing = false, $expectedcount = -1) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $params = self::validate_parameters(self::sync_students_parameters(), [
            'courseid' => $courseid, 'students' => $students,
            'removemissing' => $removemissing, 'expectedcount' => $expectedcount,
        ]);

        $course = get_course($params['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('enrol/manual:enrol', $context);
        if ($params['removemissing']) {
            require_capability('enrol/manual:unenrol', $context);
        }

        $received = count($params['students']);
        if ($received > self::MAX_STUDENTS) {
            throw new moodle_exception('toomanystudents', 'local_extsync', '', self::MAX_STUDENTS);
        }
        if ($params['expectedcount'] < 0) {
            throw new moodle_exception('countrequired', 'local_extsync');
        }
        if ($params['expectedcount'] !== $received) {
            throw new moodle_exception(
                'countmismatch',
                'local_extsync',
                '',
                (object)['received' => $received, 'expected' => $params['expectedcount']]
            );
        }
        if ($params['removemissing'] && $received === 0) {
            throw new moodle_exception('emptystudentlist', 'local_extsync');
        }

        // Requests for one course run one after another, so parallel requests cannot pass the
        // removal limit together.
        $lock = \core\lock\lock_config::get_lock_factory('local_extsync')
            ->get_lock('sync_students_' . $course->id, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new moodle_exception('syncinprogress', 'local_extsync');
        }
        try {
            return self::apply_student_list($course, $context, $params);
        } finally {
            $lock->release();
        }
    }

    /**
     * Enrol and unenrol according to a validated student list, holding the course's sync lock.
     *
     * @param stdClass $course the course
     * @param context_course $context course context
     * @param array $params validated sync_students parameters
     * @return array sync_students result
     */
    private static function apply_student_list(stdClass $course, context_course $context, array $params): array {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new moodle_exception('manualenrolmissing', 'local_extsync');
        }
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        // Everything is checked before a missing enrolment method is added.
        $roleid = (int)(($instance->roleid ?? 0) ?: $plugin->get_config('roleid'));
        if (!$roleid || !$DB->record_exists('role', ['id' => $roleid])) {
            throw new moodle_exception('nostudentrole', 'local_extsync');
        }
        if (!array_key_exists($roleid, get_assignable_roles($context))) {
            throw new moodle_exception('rolenotassignable', 'local_extsync');
        }
        if ($instance && (int)$instance->status !== ENROL_INSTANCE_ENABLED) {
            // Students enrolled into a disabled method would get no access.
            throw new moodle_exception('manualenroldisabled', 'local_extsync');
        }
        if (!$instance) {
            if (!$plugin->can_add_instance($course->id)) {
                throw new moodle_exception('manualenrolmissing', 'local_extsync');
            }
            $instance = $DB->get_record('enrol', ['id' => $plugin->add_default_instance($course)], '*', MUST_EXIST);
        }
        if ($params['removemissing'] && !$plugin->allow_unenrol($instance)) {
            throw new moodle_exception('unenrolnotallowed', 'local_extsync');
        }

        // Match the keys to Moodle users.
        $wanted = [];
        $protected = [];
        $unmatched = [];
        $ambiguous = [];
        foreach ($params['students'] as $row) {
            [$userid, $key, $candidates] = self::match_user($row);
            if ($userid > 0) {
                $wanted[$userid] = true;
            } else if ($candidates) {
                $ambiguous[$key] = true;
                foreach ($candidates as $candidate) {
                    $protected[$candidate] = true;
                }
            } else if ($key !== '') {
                $unmatched[$key] = true;
            }
        }

        // Current manual enrolments, and who could be removed.
        $current = array_fill_keys(array_map(
            'intval',
            $DB->get_fieldset_select('user_enrolments', 'userid', 'enrolid = :enrolid', ['enrolid' => $instance->id])
        ), true);
        $students = 0;
        $toremove = [];
        foreach (array_keys($current) as $userid) {
            $roleids = array_unique(array_map(function ($assignment) {
                return (int)$assignment->roleid;
            }, get_user_roles($context, $userid, false)));
            if (!in_array($roleid, $roleids, true)) {
                continue;
            }
            $students++;
            if (
                $params['removemissing'] && !isset($wanted[$userid]) && !isset($protected[$userid])
                    && count($roleids) === 1
            ) {
                $toremove[] = $userid;
            }
        }

        // Removals add up over REMOVAL_PERIOD, so several smaller requests cannot remove more than one
        // request may. The limit is based on the class size recorded at the start of the period, so
        // students enrolled in between do not raise it.
        $now = \core\di::get(\core\clock::class)->time();
        $DB->delete_records_select(
            'local_extsync_sync',
            'courseid = :courseid AND timecreated < :since',
            ['courseid' => $course->id, 'since' => $now - self::REMOVAL_PERIOD]
        );
        $history = $DB->get_records('local_extsync_sync', ['courseid' => $course->id], 'timecreated ASC, id ASC');
        $baseline = $history ? (int)reset($history)->students : $students;
        $removed = (int)array_sum(array_column($history, 'removed'));
        if ($toremove && $removed + count($toremove) > self::MAX_REMOVAL_RATIO * $baseline) {
            throw new moodle_exception(
                'removaltoolarge',
                'local_extsync',
                '',
                (object)['remove' => count($toremove), 'removed' => $removed, 'total' => $baseline]
            );
        }
        // Recorded before anything changes: an interrupted request still counts its removals.
        $DB->insert_record('local_extsync_sync', [
            'courseid' => $course->id,
            'students' => $students,
            'removed' => count($toremove),
            'timecreated' => $now,
        ]);

        $enrolled = 0;
        $already = 0;
        foreach (array_keys($wanted) as $userid) {
            if (isset($current[$userid])) {
                $already++;
                continue;
            }
            $plugin->enrol_user($instance, $userid, $roleid);
            $enrolled++;
        }
        foreach ($toremove as $userid) {
            $plugin->unenrol_user($instance, $userid);
        }

        return [
            'enrolled' => $enrolled,
            'already' => $already,
            'unenrolled' => count($toremove),
            'unmatched' => array_map('strval', array_keys($unmatched)),
            'ambiguous' => array_map('strval', array_keys($ambiguous)),
        ];
    }

    /**
     * Parameters of push_exam.
     *
     * @return external_function_parameters
     */
    public static function push_exam_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Target course id'),
            'sectionid' => new external_value(
                PARAM_INT,
                'Existing course_sections.id, 0 to create one',
                VALUE_DEFAULT,
                0
            ),
            'sectionname' => new external_value(
                PARAM_TEXT,
                'Section name when one is created, empty for the default name',
                VALUE_DEFAULT,
                ''
            ),
            'quizcmid' => new external_value(
                PARAM_INT,
                'Existing quiz cmid, 0 to create',
                VALUE_DEFAULT,
                0
            ),
            'name' => new external_value(PARAM_TEXT, 'Quiz name'),
            'intro' => new external_value(PARAM_RAW, 'Quiz description, HTML', VALUE_DEFAULT, ''),
            'xml' => new external_value(PARAM_RAW, 'Questions in Moodle XML'),
            'replace' => new external_value(
                PARAM_BOOL,
                'Replace the questions of an existing quiz',
                VALUE_DEFAULT,
                true
            ),
            'force' => new external_value(
                PARAM_BOOL,
                'Ignored. Moodle never allows replacing questions of an attempted quiz.',
                VALUE_DEFAULT,
                false
            ),
            'timeopen' => new external_value(PARAM_INT, 'Unix time, 0 for none', VALUE_DEFAULT, 0),
            'timeclose' => new external_value(PARAM_INT, 'Unix time, 0 for none', VALUE_DEFAULT, 0),
            'timelimit' => new external_value(PARAM_INT, 'Seconds, 0 for none', VALUE_DEFAULT, 0),
            'attempts' => new external_value(PARAM_INT, 'Allowed attempts, 0 = unlimited', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Return structure of push_exam.
     *
     * @return external_single_structure
     */
    public static function push_exam_returns(): external_single_structure {
        return new external_single_structure([
            'sectionid' => new external_value(PARAM_INT, 'Section the quiz sits in'),
            'quizcmid' => new external_value(PARAM_INT, 'Quiz cmid'),
            'questions' => new external_value(PARAM_INT, 'Questions now in the quiz'),
            'created' => new external_value(PARAM_BOOL, 'A new quiz was made'),
            'replaced' => new external_value(PARAM_BOOL, 'Existing questions were swapped'),
            'attemptcount' => new external_value(PARAM_INT, 'Finished attempts found before the change'),
            'courseurl' => new external_value(PARAM_RAW, 'Course URL'),
            'warnings' => new external_warnings(),
        ]);
    }

    /**
     * Place a quiz-only exam in a course, or replace the questions of an owned exam.
     *
     * Questions of an exam with any attempt are never replaced: Moodle forbids editing the
     * structure of an attempted quiz. The old questions are removed and the new ones imported inside
     * one transaction, so a failed import leaves the exam unchanged. Nothing is changed, and the exam
     * is not moved, before every check has passed.
     *
     * @param int $courseid course id
     * @param int $sectionid section id or 0
     * @param string $sectionname name of a new section
     * @param int $quizcmid existing quiz cmid or 0
     * @param string $name quiz name
     * @param string $intro quiz description
     * @param string $xml questions
     * @param bool $replace replace the questions of an existing quiz
     * @param bool $force ignored
     * @param int $timeopen open time
     * @param int $timeclose close time
     * @param int $timelimit time limit
     * @param int $attempts allowed attempts
     * @return array
     */
    public static function push_exam(
        $courseid,
        $sectionid = 0,
        $sectionname = '',
        $quizcmid = 0,
        $name = '',
        $intro = '',
        $xml = '',
        $replace = true,
        $force = false,
        $timeopen = 0,
        $timeclose = 0,
        $timelimit = 0,
        $attempts = 1
    ) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $params = self::validate_parameters(self::push_exam_parameters(), [
            'courseid' => $courseid, 'sectionid' => $sectionid, 'sectionname' => $sectionname,
            'quizcmid' => $quizcmid, 'name' => $name, 'intro' => $intro, 'xml' => $xml,
            'replace' => $replace, 'force' => $force, 'timeopen' => $timeopen,
            'timeclose' => $timeclose, 'timelimit' => $timelimit, 'attempts' => $attempts,
        ]);

        $course = get_course($params['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        if (trim($params['xml']) === '') {
            throw new moodle_exception('emptyexam', 'local_extsync');
        }

        $warnings = [];
        $settings = [];
        foreach (['timeopen', 'timeclose', 'timelimit', 'attempts'] as $field) {
            if (!empty($params[$field])) {
                $settings[$field] = (int)$params[$field];
            }
        }
        $introhtml = clean_text($params['intro'], FORMAT_HTML);
        if (trim($introhtml) !== '') {
            $settings['intro'] = $introhtml;
        }
        $courseurl = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);

        $section = $params['sectionid']
            ? $DB->get_record('course_sections', ['id' => $params['sectionid'], 'course' => $course->id])
            : false;
        $existing = self::owned($course, $params['quizcmid'], 'quiz', $warnings);

        if (!$existing) {
            if (!$section) {
                require_capability('moodle/course:update', $context);
            }
            require_capability('mod/quiz:addinstance', $context);
            require_capability('mod/quiz:manage', $context);
            require_capability('moodle/question:add', $context);

            $newsection = !$section;
            if ($newsection) {
                $section = course_create_section($course->id);
                course_update_section($course, $section, ['name' => $params['sectionname'] !== ''
                    ? $params['sectionname'] : get_string('defaultexamsection', 'local_extsync')]);
            }
            try {
                [$cmid, $count] = self::create_quiz($course, (int)$section->section, $params['name'], $params['xml'], $settings);
            } catch (\Throwable $e) {
                if ($newsection) {
                    // Only the section this call created, and only while it is empty.
                    course_delete_section($course, $section, false);
                }
                throw $e;
            }

            return [
                'sectionid' => (int)$section->id, 'quizcmid' => $cmid, 'questions' => $count,
                'created' => true, 'replaced' => false, 'attemptcount' => 0,
                'courseurl' => $courseurl, 'warnings' => $warnings,
            ];
        }

        $modcontext = context_module::instance($existing->id);
        require_capability('moodle/course:manageactivities', $modcontext);
        $target = $section;
        $section = $target ?: $DB->get_record('course_sections', ['id' => $existing->section], '*', MUST_EXIST);

        $finished = $DB->count_records(
            'quiz_attempts',
            ['quiz' => $existing->instance, 'state' => \mod_quiz\quiz_attempt::FINISHED, 'preview' => 0]
        );

        if (!$params['replace']) {
            if ($target) {
                modules::move_to_section($course, $existing, $target);
            }
            if ($settings) {
                // Dates and limits only change together with the questions.
                $warnings[] = self::warning(
                    'module',
                    (int)$existing->id,
                    'settingsnotapplied',
                    get_string('settingsnotapplied', 'local_extsync', $existing->id)
                );
            }
            return [
                'sectionid' => (int)$section->id, 'quizcmid' => (int)$existing->id,
                'questions' => $DB->count_records('quiz_slots', ['quizid' => $existing->instance]),
                'created' => false, 'replaced' => false, 'attemptcount' => $finished,
                'courseurl' => $courseurl, 'warnings' => $warnings,
            ];
        }

        require_capability('mod/quiz:manage', $modcontext);
        require_capability('moodle/question:add', $modcontext);

        // Replacements of one exam run one after another. Without this, a concurrent replacement fails
        // on the questions the other one already replaced.
        $lock = \core\lock\lock_config::get_lock_factory('local_extsync')
            ->get_lock('push_exam_' . $existing->id, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new moodle_exception('examinprogress', 'local_extsync');
        }
        try {
            $allattempts = $DB->count_records('quiz_attempts', ['quiz' => $existing->instance, 'preview' => 0]);
            if ($allattempts > 0) {
                throw new moodle_exception('examhasattempts', 'local_extsync', '', $allattempts);
            }

            // Moved only now: a refused replacement leaves the exam where it was.
            if ($target) {
                modules::move_to_section($course, $existing, $target);
            }
            $count = self::replace_quiz_questions($existing, $course, $params['xml']);
            modules::update_quiz_settings($existing, $course, $settings);
        } finally {
            $lock->release();
        }

        return [
            'sectionid' => (int)$section->id, 'quizcmid' => (int)$existing->id, 'questions' => $count,
            'created' => false, 'replaced' => true, 'attemptcount' => $finished,
            'courseurl' => $courseurl, 'warnings' => $warnings,
        ];
    }

    /**
     * An owned module, or null with a warning when the caller sent an id that is not owned.
     *
     * @param stdClass $course the course
     * @param int $cmid course module id from the caller
     * @param string|null $modname expected module type, null for any
     * @param array $warnings warnings to add to
     * @return stdClass|null
     */
    private static function owned(stdClass $course, int $cmid, ?string $modname, array &$warnings): ?stdClass {
        if ($cmid <= 0) {
            return null;
        }
        $cm = ownership::get_cm((int)$course->id, $cmid, $modname);
        if (!$cm) {
            $warnings[] = self::warning('module', $cmid, 'notowned', get_string('notowned', 'local_extsync', $cmid));
        }

        return $cm;
    }

    /**
     * A warning in the core external_warnings format.
     *
     * @param string $item item type
     * @param int $itemid item id
     * @param string $code warning code
     * @param string $message message
     * @return array
     */
    private static function warning(string $item, int $itemid, string $code, string $message): array {
        return ['item' => $item, 'itemid' => $itemid, 'warningcode' => $code, 'message' => $message];
    }

    /**
     * Find the one Moodle user a student row identifies.
     *
     * Every key given is looked up (idnumber, username, email). The row matches when all the accounts found by
     * its keys are one account; several accounts, from one key or from different keys, make the row ambiguous
     * and all of them are candidates. A key that finds no account does not prevent a match. The guest account
     * is never matched. ID numbers and emails are
     * compared case-insensitively on every database; suspended accounts are matched like others,
     * because Moodle already blocks their sign-in and unenrolling them would lose their progress.
     *
     * @param array $row student row
     * @return array [user id or 0, the key used, candidate ids when ambiguous]
     */
    private static function match_user(array $row): array {
        global $CFG, $DB;

        $keys = [
            'idnumber' => trim($row['idnumber']),
            'username' => \core_text::strtolower(trim($row['username'])),
            'email' => trim($row['email']),
        ];
        $first = '';
        $key = '';
        $candidates = [];
        foreach ($keys as $field => $value) {
            if ($value === '') {
                continue;
            }
            $first = $first !== '' ? $first : $value;
            $equal = $DB->sql_equal($field, ':value', $field === 'username', true);
            $ids = array_map('intval', $DB->get_fieldset_select(
                'user',
                'id',
                "deleted = 0 AND mnethostid = :mnethostid AND id <> :guestid AND $equal",
                ['mnethostid' => $CFG->mnet_localhost_id, 'guestid' => $CFG->siteguest, 'value' => $value]
            ));
            if ($ids) {
                $key = $key !== '' ? $key : $value;
                $candidates = array_merge($candidates, $ids);
            }
        }
        $candidates = array_values(array_unique($candidates));
        if (count($candidates) > 1) {
            // One key names several people, or the keys name different people: picking one could enrol, or
            // keep, the wrong person. Every candidate is protected from removal.
            return [0, $key, $candidates];
        }

        return $candidates ? [$candidates[0], $key, []] : [0, $first, []];
    }

    /**
     * Whether a section summary holds nothing a teacher wrote: no text and no media.
     *
     * @param string|null $summary section summary, HTML
     * @return bool
     */
    private static function summary_is_empty(?string $summary): bool {
        $summary = (string)$summary;

        return trim(html_to_text($summary, 0, false)) === ''
            && !preg_match('/<(img|video|audio|iframe|object|embed|svg|picture)\b/i', $summary);
    }

    /**
     * Prepare the unit section and the lesson subsection inside it.
     *
     * @param stdClass $course the course
     * @param array $params push_lesson parameters
     * @param array $warnings warnings to add to
     * @return array [delegated section or false, unit section id, subsection cmid, created]
     */
    private static function subsection_for(stdClass $course, array $params, array &$warnings): array {
        global $DB;

        if (!$DB->record_exists('modules', ['name' => 'subsection', 'visible' => 1])) {
            return [false, 0, 0, false];
        }
        // Checked before the unit section is created, so a refusal leaves nothing behind.
        $cm = self::owned($course, $params['subsectioncmid'], 'subsection', $warnings);
        $canadd = course_allowed_module($course, 'subsection')
            && has_capability('mod/subsection:addinstance', context_course::instance($course->id));
        if (!$cm && !$canadd) {
            return [false, 0, 0, false];
        }

        $unit = $params['unitsectionid']
            ? $DB->get_record('course_sections', ['id' => $params['unitsectionid'], 'course' => $course->id])
            : false;
        if (!$unit) {
            foreach ($DB->get_records('course_sections', ['course' => $course->id], 'section') as $candidate) {
                if (
                    empty($candidate->component) && trim((string)$candidate->name) !== ''
                        && trim((string)$candidate->name) === trim($params['unitname'])
                ) {
                    $unit = $candidate;
                    break;
                }
            }
        }
        if (!$unit) {
            $unit = course_create_section($course->id);
            course_update_section($course, $unit, ['name' => $params['unitname']]);
        }

        $created = false;
        if ($cm) {
            $cmid = (int)$cm->id;
            $instance = (int)$cm->instance;
        } else {
            $cmid = modules::create(modules::base_data($course, (int)$unit->section, 'subsection', $params['name']));
            $instance = (int)$DB->get_field('course_modules', 'instance', ['id' => $cmid]);
            $created = true;
        }

        $delegated = $DB->get_record(
            'course_sections',
            ['course' => $course->id, 'component' => 'mod_subsection', 'itemid' => $instance]
        );

        return [$delegated ?: false, (int)$unit->id, $cmid, $created];
    }

    /**
     * Publish one downloaded material as a file resource.
     *
     * @param stdClass $course the course
     * @param int $sectionnum section number
     * @param array $file material item
     * @param string $path downloaded file
     * @return int course module id
     */
    private static function create_resource(stdClass $course, int $sectionnum, array $file, string $path): int {
        global $USER;

        // A missing file on the source often redirects to an HTML page that answers 200.
        $handle = fopen($path, 'rb');
        $head = (string)fread($handle, 512);
        fclose($handle);
        if (!preg_match('/\.html?$/i', $file['name']) && preg_match('~^\s*(<!doctype\s+html|<html[\s>])~i', $head)) {
            throw new moodle_exception('downloadhtml', 'local_extsync', '', $file['url']);
        }

        // Files reach a module through a draft area, which fills the resource file records correctly.
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => clean_param($file['name'], PARAM_FILE),
        ], $path);

        $name = $file['description'] !== '' ? $file['description'] : $file['name'];

        return modules::create(modules::resource_data($course, $sectionnum, $name, $draftitemid));
    }

    /**
     * Create a quiz and fill it from Moodle XML. The quiz is deleted again when anything fails.
     *
     * @param stdClass $course the course
     * @param int $sectionnum section number
     * @param string $name quiz name
     * @param string $xml questions
     * @param array $settings quiz fields to set instead of the site defaults
     * @return array [cmid, imported question count]
     */
    private static function create_quiz(
        stdClass $course,
        int $sectionnum,
        string $name,
        string $xml,
        array $settings = []
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $cmid = modules::create(modules::quiz_data($course, $sectionnum, $name, $settings));
        try {
            $refs = question_import::import($course, context_module::instance($cmid), $xml);
            ownership::record_questions($cmid, $refs);
            $quiz = $DB->get_record(
                'quiz',
                ['id' => $DB->get_field('course_modules', 'instance', ['id' => $cmid])],
                '*',
                MUST_EXIST
            );
            $quiz->cmid = $cmid;
            foreach (array_keys($refs) as $questionid) {
                quiz_add_quiz_question($questionid, $quiz, 0);
            }
            \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

            return [$cmid, count($refs)];
        } catch (\Throwable $e) {
            modules::delete($course, $cmid);
            throw $e;
        }
    }

    /**
     * Swap all questions of an unattempted quiz, atomically.
     *
     * Afterwards only questions this plugin imported for the quiz are deleted, and only when they
     * are unused and the caller may edit them. Questions written by teachers, or imported by plugin
     * versions that did not record provenance, stay in the question bank.
     *
     * @param stdClass $cm the quiz course module
     * @param stdClass $course the course
     * @param string $xml new questions
     * @return int number of questions now in the quiz
     */
    private static function replace_quiz_questions(stdClass $cm, stdClass $course, string $xml): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');

        $context = context_module::instance($cm->id);
        $transaction = $DB->start_delegated_transaction();
        try {
            $oldids = ownership::question_ids($context);

            // A quiz structure object stays consistent for one change only, as on the quiz editing
            // page, so it is loaded again for every slot. Removing from the end avoids renumbering.
            for ($slot = $DB->count_records('quiz_slots', ['quizid' => $cm->instance]); $slot >= 1; $slot--) {
                $structure = \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($cm->instance));
                $structure->remove_slot($slot);
            }

            // The replaced questions the plugin imported are no longer used by this quiz; keep the bank
            // from growing. Anything else, or anything the caller could not delete in Moodle, is kept.
            // They go before the import, so the new questions can take over their Moodle ID numbers; a
            // failed import rolls the whole replacement back.
            foreach ($oldids as $questionid) {
                if (!questions_in_use([$questionid]) && question_has_capability_on($questionid, 'edit')) {
                    question_delete_question($questionid);
                    ownership::forget_question($questionid);
                }
            }

            $refs = question_import::import($course, $context, $xml);
            ownership::record_questions((int)$cm->id, $refs);
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            $quiz->cmid = $cm->id;
            foreach (array_keys($refs) as $questionid) {
                quiz_add_quiz_question($questionid, $quiz, 0);
            }
            \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

            // A student may have started the exam while it was being replaced; that attempt uses the old
            // questions, so the whole replacement is rolled back.
            $attempts = $DB->count_records('quiz_attempts', ['quiz' => $cm->instance, 'preview' => 0]);
            if ($attempts > 0) {
                throw new moodle_exception('examhasattempts', 'local_extsync', '', $attempts);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return count($refs);
    }

    /**
     * Question id => the external system reference for the questions of one attempt.
     *
     * References come from the plugin's provenance records, so they do not depend on the Moodle ID
     * number, which the importer drops when it is already used in the question bank. An exam without any
     * provenance record was created by an earlier plugin version; its questions are identified by their
     * Moodle ID numbers, as before.
     *
     * @param \mod_quiz\quiz_attempt $attemptobj the attempt
     * @param int $cmid quiz course module id
     * @return array<int,string>
     */
    private static function question_refs($attemptobj, int $cmid): array {
        global $DB;

        $ids = [];
        foreach ($attemptobj->get_slots() as $slot) {
            $ids[] = $attemptobj->get_question_attempt($slot)->get_question_id();
        }
        $ids = array_values(array_unique($ids));
        $refs = ownership::question_refs($cmid, $ids);
        if ($refs !== null || !$ids) {
            return $refs ?? [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);

        return $DB->get_records_sql_menu(
            "SELECT q.id, qbe.idnumber
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE q.id $insql",
            $inparams
        );
    }
}
