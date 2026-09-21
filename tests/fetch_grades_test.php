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

use core_external\external_api;

/**
 * Grade retrieval tests.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\external::fetch_grades
 */
final class fetch_grades_test extends \advanced_testcase {
    /** @var \stdClass course */
    private $course;

    /** @var int quiz course module id */
    private $cmid;

    /** @var \stdClass[] students with a finished attempt */
    private $students = [];

    /**
     * A the external system exam with two students who finished it.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $service = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($service);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><quiz><question type="truefalse"><name><text>G1</text></name>'
            . '<questiontext format="html"><text>Statement</text></questiontext><idnumber>chk-9</idnumber>'
            . '<answer fraction="100"><text>true</text></answer><answer fraction="0"><text>false</text></answer>'
            . '</question></quiz>';
        $result = external::push_exam($this->course->id, 0, '', 0, 'Exam', '', $xml);
        $this->cmid = $result['quizcmid'];

        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $this->cmid]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        foreach (['one', 'two'] as $name) {
            $student = $this->getDataGenerator()->create_and_enrol(
                $this->course,
                'student',
                ['username' => $name, 'idnumber' => 'ID-' . $name, 'email' => $name . '@example.com']
            );
            // Attempts started by a teacher are previews, which are never reported.
            $this->setUser($student);
            $attempt = $generator->create_attempt($quizid, $student->id);
            $generator->submit_responses($attempt->id, [], false, true);
            $this->students[$name] = $student;
        }
    }

    /**
     * Call fetch_grades as the given user.
     *
     * @param \stdClass $user caller
     * @return array rows by user id
     */
    private function fetch(\stdClass $user): array {
        $this->setUser($user);
        $rows = external_api::clean_returnvalue(
            external::fetch_grades_returns(),
            external::fetch_grades($this->course->id, $this->cmid)
        );
        return array_column($rows, null, 'moodleuserid');
    }

    /**
     * Identity fields follow showuseridentity and moodle/site:viewuseridentity.
     */
    public function test_identity_fields_are_filtered(): void {
        global $DB;
        $caller = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $one = $this->students['one'];

        set_config('showuseridentity', 'email');
        $row = $this->fetch($caller)[$one->id];
        $this->assertSame('one@example.com', $row['email']);
        $this->assertSame('', $row['idnumber']);
        $this->assertSame('', $row['username']);
        $this->assertCount(1, $row['questions']);
        $this->assertSame('chk-9', $row['questions'][0]['ref']);

        set_config('showuseridentity', 'idnumber,email,username');
        $row = $this->fetch($caller)[$one->id];
        $this->assertSame('ID-one', $row['idnumber']);
        $this->assertSame('one', $row['username']);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $coursecontextid = \context_course::instance($this->course->id)->id;
        assign_capability('moodle/site:viewuseridentity', CAP_PROHIBIT, $roleid, $coursecontextid, true);
        $row = $this->fetch($caller)[$one->id];
        $this->assertSame('', $row['email']);
        $this->assertSame('', $row['idnumber']);
        $this->assertSame('', $row['username']);
    }

    /**
     * mod/quiz:viewreports is checked in the quiz context.
     */
    public function test_viewreports_checked_in_module_context(): void {
        global $DB;
        $caller = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('mod/quiz:viewreports', CAP_PROHIBIT, $roleid, \context_module::instance($this->cmid)->id, true);

        $this->expectException(\required_capability_exception::class);
        $this->fetch($caller);
    }

    /**
     * Only current participants are reported, as in the quiz reports: attempts of unenrolled and deleted users are not.
     */
    public function test_only_current_participants_are_reported(): void {
        global $DB;
        $caller = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $this->cmid]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $three = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($three);
        $generator->submit_responses($generator->create_attempt($quizid, $three->id)->id, [], false, true);

        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $this->students['two']->id);
        delete_user($three);

        $this->assertSame([(int)$this->students['one']->id], array_keys($this->fetch($caller)));
    }

    /**
     * A student whose enrolment is suspended is still a participant, as in the quiz reports.
     */
    public function test_suspended_enrolment_is_reported(): void {
        global $DB;
        $caller = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol($instance, $this->students['two']->id, ENROL_USER_SUSPENDED);

        $this->assertEqualsCanonicalizing(
            [(int)$this->students['one']->id, (int)$this->students['two']->id],
            array_keys($this->fetch($caller))
        );
    }

    /**
     * Results of a hidden quiz are only returned to a caller who may see hidden activities.
     */
    public function test_hidden_quiz_needs_viewhiddenactivities(): void {
        global $DB;
        $caller = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        set_coursemodule_visible($this->cmid, 0);
        $this->assertCount(2, $this->fetch($caller));

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $contextid = \context_module::instance($this->cmid)->id;
        assign_capability('moodle/course:viewhiddenactivities', CAP_PROHIBIT, $roleid, $contextid, true);
        // A new web service request starts with empty permission and course module caches.
        accesslib_clear_all_caches_for_unit_testing();
        get_fast_modinfo($this->course->id, 0, true);

        $this->expectException(\moodle_exception::class);
        $this->fetch($caller);
    }

    /**
     * An attempt whose questions still need grading has no grade yet, rather than 0.
     */
    public function test_ungraded_attempt_has_no_grade(): void {
        global $DB;
        $caller = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($caller);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><quiz><question type="essay"><name><text>Essay</text></name>'
            . '<questiontext format="html"><text>Write</text></questiontext><idnumber>essay-1</idnumber>'
            . '<defaultgrade>1</defaultgrade><responseformat>editor</responseformat><responserequired>1</responserequired>'
            . '<responsefieldlines>5</responsefieldlines><attachments>0</attachments>'
            . '<graderinfo format="html"><text></text></graderinfo><responsetemplate format="html"><text></text></responsetemplate>'
            . '</question></quiz>';
        $cmid = external::push_exam($this->course->id, 0, '', 0, 'Essay exam', '', $xml)['quizcmid'];
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $generator->submit_responses(
            $generator->create_attempt($quizid, $student->id)->id,
            [1 => 'My essay'],
            false,
            true
        );

        $this->setUser($caller);
        $rows = external_api::clean_returnvalue(
            external::fetch_grades_returns(),
            external::fetch_grades($this->course->id, $cmid)
        );

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['grade']);
        $this->assertSame('essay-1', $rows[0]['questions'][0]['ref']);
    }

    /**
     * With separate groups, a caller without access to all groups only sees their groups.
     */
    public function test_separate_groups(): void {
        global $DB;
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cmid]);
        rebuild_course_cache($this->course->id, true);
        $caller = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $caller->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->students['one']->id]);

        $rows = $this->fetch($caller);

        $this->assertSame([(int)$this->students['one']->id], array_keys($rows));
    }
}
