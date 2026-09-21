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

namespace local_extsync\local;

use mod_quiz\question\display_options;
use stdClass;

/**
 * Creates and changes the course modules the plugin manages.
 *
 * New modules start from the site defaults of their module type. Existing modules are changed
 * through update_module() with their complete current settings, so a push only changes what the external system
 * owns (names, descriptions, page content, exam times) and keeps everything a teacher configured.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modules {
    /** @var string[] Quiz review option fields. */
    public const QUIZ_REVIEW_FIELDS = ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback',
        'generalfeedback', 'rightanswer', 'overallfeedback'];

    /** @var int[] Quiz review option times, form field suffix => bit. */
    public const QUIZ_REVIEW_TIMES = [
        'during' => display_options::DURING,
        'immediately' => display_options::IMMEDIATELY_AFTER,
        'open' => display_options::LATER_WHILE_OPEN,
        'closed' => display_options::AFTER_CLOSE,
    ];

    /**
     * Create a module and record that the plugin owns it.
     *
     * @param stdClass $data module data for create_module()
     * @return int course module id
     */
    public static function create(stdClass $data): int {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $info = create_module($data);
        ownership::record((int)$data->course, (int)$info->coursemodule, $data->modulename);

        return (int)$info->coursemodule;
    }

    /**
     * Fields every new module needs.
     *
     * @param stdClass $course the course
     * @param int $sectionnum section number
     * @param string $modname module type
     * @param string $name module name
     * @return stdClass
     */
    public static function base_data(stdClass $course, int $sectionnum, string $modname, string $name): stdClass {
        $data = new stdClass();
        $data->modulename = $modname;
        $data->course = $course->id;
        $data->section = $sectionnum;
        $data->name = $name;
        $data->intro = '';
        $data->introformat = FORMAT_HTML;
        $data->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $data->visible = get_config('local_extsync', 'createhidden') ? 0 : 1;
        $data->visibleoncoursepage = 1;
        $data->cmidnumber = '';
        $data->groupmode = (int)$course->groupmode;
        $data->groupingid = (int)$course->defaultgroupingid;
        $data->completion = COMPLETION_TRACKING_NONE;
        $data->completionexpected = 0;
        $data->availabilityconditionsjson = '';

        return $data;
    }

    /**
     * Data for a new page.
     *
     * @param stdClass $course the course
     * @param int $sectionnum section number
     * @param string $name page name
     * @param string $html cleaned page content
     * @return stdClass
     */
    public static function page_data(stdClass $course, int $sectionnum, string $name, string $html): stdClass {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $config = get_config('page');
        $data = self::base_data($course, $sectionnum, 'page', $name);
        $data->content = $html;
        $data->contentformat = FORMAT_HTML;
        $data->page = ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => 0];
        $data->display = $config->display ?? RESOURCELIB_DISPLAY_OPEN;
        $data->popupwidth = $config->popupwidth ?? 620;
        $data->popupheight = $config->popupheight ?? 450;
        $data->printintro = $config->printintro ?? 0;
        $data->printlastmodified = $config->printlastmodified ?? 1;

        return $data;
    }

    /**
     * Data for a new file resource.
     *
     * @param stdClass $course the course
     * @param int $sectionnum section number
     * @param string $name resource name
     * @param int $draftitemid draft area holding the file
     * @return stdClass
     */
    public static function resource_data(stdClass $course, int $sectionnum, string $name, int $draftitemid): stdClass {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $config = get_config('resource');
        $data = self::base_data($course, $sectionnum, 'resource', $name);
        $data->files = $draftitemid;
        $data->display = $config->display ?? RESOURCELIB_DISPLAY_AUTO;
        $data->popupwidth = $config->popupwidth ?? 620;
        $data->popupheight = $config->popupheight ?? 450;
        $data->printintro = $config->printintro ?? 1;
        $data->showsize = $config->showsize ?? 0;
        $data->showtype = $config->showtype ?? 0;
        $data->showdate = $config->showdate ?? 0;
        $data->filterfiles = $config->filterfiles ?? 0;
        $data->revision = 1;

        return $data;
    }

    /**
     * Data for a new assignment, from the site's assignment defaults.
     *
     * Dates are left empty for the teacher to set.
     *
     * @param stdClass $course the course
     * @param int $sectionnum section number
     * @param string $name assignment name
     * @param string $intro cleaned description
     * @return stdClass
     */
    public static function assign_data(stdClass $course, int $sectionnum, string $name, string $intro): stdClass {
        global $CFG;

        $config = get_config('assign');
        $data = self::base_data($course, $sectionnum, 'assign', $name);
        $data->intro = $intro;
        $data->introeditor['text'] = $intro;

        $defaults = [
            'alwaysshowdescription' => 1,
            'submissiondrafts' => 0,
            'requiresubmissionstatement' => 0,
            'sendnotifications' => 0,
            'sendlatenotifications' => 0,
            'sendstudentnotifications' => 1,
            'teamsubmission' => 0,
            'requireallteammemberssubmit' => 0,
            'blindmarking' => 0,
            'hidegrader' => 0,
            'attemptreopenmethod' => 'none',
            'maxattempts' => -1,
            'markingworkflow' => 0,
            'markingallocation' => 0,
            'markinganonymous' => 0,
            'preventsubmissionnotingroup' => 0,
        ];
        foreach ($defaults as $field => $default) {
            $data->$field = $config->$field ?? $default;
        }
        $data->teamsubmissiongroupingid = 0;
        $data->activityeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $data->timelimit = 0;
        $data->allowsubmissionsfromdate = 0;
        $data->duedate = 0;
        $data->cutoffdate = 0;
        $data->gradingduedate = 0;
        $data->grade = $CFG->gradepointdefault ?? 100;

        // Submission and feedback plugins shipped with Moodle, from their site defaults.
        $data->assignsubmission_onlinetext_enabled = (int)get_config('assignsubmission_onlinetext', 'default');
        $data->assignsubmission_onlinetext_wordlimit_enabled = 0;
        $data->assignsubmission_onlinetext_wordlimit = 0;
        $data->assignsubmission_file_enabled = (int)get_config('assignsubmission_file', 'default');
        $data->assignsubmission_file_maxfiles = (int)get_config('assignsubmission_file', 'maxfiles');
        $data->assignsubmission_file_maxsizebytes = (int)get_config('assignsubmission_file', 'maxbytes');
        $data->assignsubmission_file_filetypes = (string)get_config('assignsubmission_file', 'filetypes');
        $data->assignsubmission_comments_enabled = 1;
        $data->assignfeedback_comments_enabled = (int)get_config('assignfeedback_comments', 'default');
        $data->assignfeedback_comments_commentinline = (int)get_config('assignfeedback_comments', 'inline');
        $data->assignfeedback_file_enabled = (int)get_config('assignfeedback_file', 'default');
        $data->assignfeedback_offline_enabled = (int)get_config('assignfeedback_offline', 'default');
        $data->assignfeedback_editpdf_enabled = (int)get_config('assignfeedback_editpdf', 'default');

        return $data;
    }

    /**
     * Data for a new quiz, from the site's quiz defaults.
     *
     * @param stdClass $course the course
     * @param int $sectionnum section number
     * @param string $name quiz name
     * @param array $overrides fields to set instead of the defaults, for example timeopen or intro
     * @return stdClass
     */
    public static function quiz_data(stdClass $course, int $sectionnum, string $name, array $overrides = []): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $config = get_config('quiz');
        $data = self::base_data($course, $sectionnum, 'quiz', $name);

        $defaults = [
            'timelimit' => 0,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 0,
            'preferredbehaviour' => 'deferredfeedback',
            'canredoquestions' => 0,
            'attempts' => 0,
            'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'shuffleanswers' => 1,
            'questionsperpage' => 1,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
            'showuserpicture' => 0,
            'showblocks' => 0,
            'quizpassword' => '',
            'subnet' => '',
            'delay1' => 0,
            'delay2' => 0,
            'browsersecurity' => '-',
        ];
        foreach ($defaults as $field => $default) {
            $data->$field = $config->$field ?? $default;
        }
        $data->grade = $config->maximumgrade ?? 10;
        $data->timeopen = 0;
        $data->timeclose = 0;
        $data->sumgrades = 0;
        $data->gradepass = 0;
        $data->allowofflineattempts = 0;
        $data->completionattemptsexhausted = 0;
        $data->completionminattempts = 0;
        $data->feedbacktext = [['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0]];
        $data->feedbackboundaries = [];
        self::set_review_fields($data, $config, 'review');

        foreach ($overrides as $field => $value) {
            $data->$field = $value;
        }
        if (isset($overrides['intro'])) {
            $data->introeditor['text'] = $overrides['intro'];
        }

        return $data;
    }

    /**
     * Update the name and content of an owned page.
     *
     * @param stdClass $cm the course module
     * @param stdClass $course the course
     * @param string $name new name
     * @param string $html new cleaned content
     */
    public static function update_page(stdClass $cm, stdClass $course, string $name, string $html): void {
        $data = self::existing_data($cm, $course);
        $data->name = $name;
        $data->page = ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => 0];
        update_module($data);
    }

    /**
     * Update the name and description of an owned assignment.
     *
     * @param stdClass $cm the course module
     * @param stdClass $course the course
     * @param string $name new name
     * @param string $intro new cleaned description
     */
    public static function update_assign(stdClass $cm, stdClass $course, string $name, string $intro): void {
        $data = self::existing_data($cm, $course);
        $data->name = $name;
        $data->introeditor = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => $data->introeditor['itemid']];
        update_module($data);
    }

    /**
     * Change the exam settings the external system controls, through the module update API.
     *
     * Only non-empty values are applied, so settings a teacher changed in Moodle are kept.
     *
     * @param stdClass $cm the quiz course module
     * @param stdClass $course the course
     * @param array $settings timeopen, timeclose, timelimit, attempts and intro
     */
    public static function update_quiz_settings(stdClass $cm, stdClass $course, array $settings): void {
        $data = self::existing_data($cm, $course);
        $changed = false;
        foreach (['timeopen', 'timeclose', 'timelimit', 'attempts'] as $field) {
            if (!empty($settings[$field])) {
                $data->$field = (int)$settings[$field];
                $changed = true;
            }
        }
        if (isset($settings['intro']) && $settings['intro'] !== '') {
            $data->introeditor = [
                'text' => $settings['intro'],
                'format' => FORMAT_HTML,
                'itemid' => $data->introeditor['itemid'],
            ];
            $changed = true;
        }
        if ($changed) {
            update_module($data);
        }
    }

    /**
     * Complete current settings of a module, shaped like its edit form submits them.
     *
     * @param stdClass $cm the course module
     * @param stdClass $course the course
     * @return stdClass data for update_module()
     */
    public static function existing_data(stdClass $cm, stdClass $course): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        [$cm, $context, , $data] = get_moduleinfo_data($cm, $course);

        if ($data->modulename === 'page') {
            $options = (array)unserialize_array($data->displayoptions ?? '');
            $data->printintro = $options['printintro'] ?? 0;
            $data->printlastmodified = $options['printlastmodified'] ?? 1;
            $data->popupwidth = $options['popupwidth'] ?? 620;
            $data->popupheight = $options['popupheight'] ?? 450;
            $data->page = ['text' => $data->content, 'format' => $data->contentformat, 'itemid' => 0];
        } else if ($data->modulename === 'assign') {
            self::add_assign_plugin_settings($data, $context, $cm, $course);
        } else if ($data->modulename === 'quiz') {
            self::add_quiz_form_settings($data, $context);
        }

        return $data;
    }

    /**
     * Move a module to the end of a section, unless it is already in that section.
     *
     * @param stdClass $course the course
     * @param stdClass $cm the course module
     * @param stdClass $section course_sections record
     */
    public static function move_to_section(stdClass $course, stdClass $cm, stdClass $section): void {
        if ((int)$cm->section !== (int)$section->id) {
            self::append_to_section($course, (int)$cm->id, $section);
        }
    }

    /**
     * Move a module to the end of a section.
     *
     * @param stdClass $course the course
     * @param int $cmid course module id
     * @param stdClass $section course_sections record
     */
    public static function append_to_section(stdClass $course, int $cmid, stdClass $section): void {
        global $CFG;

        // Moodle 5.2 replaced moveto_module() with course format actions.
        if (method_exists(\core_courseformat\local\cmactions::class, 'move_end_section')) {
            \core_courseformat\formatactions::cm($course)->move_end_section($cmid, (int)$section->id);
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        $mod = get_coursemodule_from_id('', $cmid, $course->id, false, MUST_EXIST);
        moveto_module($mod, $section);
    }

    /**
     * Delete a module and forget it.
     *
     * @param stdClass $course the course
     * @param int $cmid course module id
     */
    public static function delete(stdClass $course, int $cmid): void {
        global $CFG;

        // Moodle 5.2 replaced course_delete_module() with course format actions.
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            \core_courseformat\formatactions::cm($course)->delete($cmid);
        } else {
            require_once($CFG->dirroot . '/course/lib.php');
            course_delete_module($cmid);
        }
        ownership::forget($cmid);
    }

    /**
     * Why an owned module must not be deleted, if anything protects student work or other content.
     *
     * Fails closed: only module types whose deletion is understood can be deleted. Deleting a
     * subsection deletes every module in its section, so a subsection can only be deleted when each
     * of those modules is owned by the plugin and could be deleted on its own.
     *
     * @param stdClass $cm the course module, with modname
     * @return string|null language string identifier, null when it may be deleted
     */
    public static function deletion_blocker(stdClass $cm): ?string {
        global $CFG, $DB;

        switch ($cm->modname) {
            case 'page':
            case 'resource':
                return null;

            case 'quiz':
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                return quiz_has_attempts($cm->instance) ? 'quizhasattempts' : null;

            case 'assign':
                $submitted = $DB->record_exists_select(
                    'assign_submission',
                    'assignment = :assignment AND status <> :new',
                    ['assignment' => $cm->instance, 'new' => 'new']
                );
                if ($submitted || $DB->record_exists('assign_grades', ['assignment' => $cm->instance])) {
                    return 'assignhassubmissions';
                }
                return null;

            case 'subsection':
                $section = $DB->get_record(
                    'course_sections',
                    ['course' => $cm->course, 'component' => 'mod_subsection', 'itemid' => $cm->instance]
                );
                $contents = $section ? $DB->get_records('course_modules', ['section' => $section->id], 'id') : [];
                foreach ($contents as $inner) {
                    $owned = ownership::get_cm((int)$cm->course, (int)$inner->id);
                    if (!$owned || self::deletion_blocker($owned) !== null) {
                        return 'subsectionnotempty';
                    }
                }
                return null;

            default:
                return 'modulenotdeletable';
        }
    }

    /**
     * Add the submission and feedback plugin settings an assignment update needs.
     *
     * Without them update_instance() would switch every plugin off.
     *
     * @param stdClass $data module data
     * @param \context $context module context
     * @param stdClass $cm the course module
     * @param stdClass $course the course
     */
    protected static function add_assign_plugin_settings(stdClass $data, \context $context, stdClass $cm, stdClass $course): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $assign = new \assign($context, $cm, $course);
        foreach (array_merge($assign->get_submission_plugins(), $assign->get_feedback_plugins()) as $plugin) {
            $prefix = $plugin->get_subtype() . '_' . $plugin->get_type();
            $data->{$prefix . '_enabled'} = $plugin->is_enabled() ? 1 : 0;
            foreach ((array)$plugin->get_config() as $name => $value) {
                if ($name !== 'enabled' && !isset($data->{$prefix . '_' . $name})) {
                    $data->{$prefix . '_' . $name} = $value;
                }
            }
        }

        // Moodle's own plugins name some form fields differently from the stored setting.
        $aliases = [
            'assignsubmission_file_maxfiles' => 'assignsubmission_file_maxfilesubmissions',
            'assignsubmission_file_maxsizebytes' => 'assignsubmission_file_maxsubmissionsizebytes',
            'assignsubmission_file_filetypes' => 'assignsubmission_file_filetypeslist',
            'assignsubmission_onlinetext_wordlimit_enabled' => 'assignsubmission_onlinetext_wordlimitenabled',
        ];
        foreach ($aliases as $field => $stored) {
            if (isset($data->$stored)) {
                $data->$field = $data->$stored;
            }
        }
    }

    /**
     * Add the quiz settings its edit form restores: password, review options, overall feedback and
     * access rule settings. quiz_update_instance() rewrites all of them from the submitted data.
     *
     * @param stdClass $data module data
     * @param \context $context module context
     */
    protected static function add_quiz_form_settings(stdClass $data, \context $context): void {
        global $DB;

        $data->quizpassword = $data->password;
        unset($data->password);

        $record = clone($data);
        self::set_review_fields($data, $record, 'review');

        $data->feedbacktext = [];
        $data->feedbackboundaries = [];
        $feedbacks = $DB->get_records('quiz_feedback', ['quizid' => $data->instance], 'mingrade DESC');
        foreach (array_values($feedbacks) as $index => $feedback) {
            $draftitemid = 0;
            $text = file_prepare_draft_area(
                $draftitemid,
                $context->id,
                'mod_quiz',
                'feedback',
                $feedback->id,
                null,
                $feedback->feedbacktext
            );
            $data->feedbacktext[$index] = [
                'text' => $text,
                'format' => $feedback->feedbacktextformat,
                'itemid' => $draftitemid,
            ];
            if ($feedback->mingrade > 0) {
                $data->feedbackboundaries[$index] = $feedback->mingrade;
            }
        }

        foreach (\mod_quiz\access_manager::load_settings($data->instance) as $name => $value) {
            $data->$name = $value;
        }
    }

    /**
     * Turn review option bitmasks into the per-time form fields.
     *
     * @param stdClass $data object receiving the fields
     * @param stdClass $source object holding the bitmasks
     * @param string $prefix bitmask property prefix
     */
    protected static function set_review_fields(stdClass $data, stdClass $source, string $prefix): void {
        foreach (self::QUIZ_REVIEW_FIELDS as $field) {
            $mask = (int)($source->{$prefix . $field} ?? 0);
            foreach (self::QUIZ_REVIEW_TIMES as $when => $bit) {
                $data->{$field . $when} = ($mask & $bit) ? 1 : 0;
            }
        }
    }
}
