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
use local_extsync\local\materials;

/**
 * Lesson push tests.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\external::push_lesson
 * @covers     \local_extsync\local\modules
 * @covers     \local_extsync\local\ownership
 * @covers     \local_extsync\local\question_import
 */
final class push_lesson_test extends \advanced_testcase {
    /** @var \stdClass course */
    private $course;

    /** @var \stdClass web service user */
    private $service;

    /**
     * Course with a teacher acting as the web service account, and fake material downloads.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->service = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->service);
        set_config('allowedhosts', 'files.example.com', 'local_extsync');
        $this->fake_downloads([
            'https://files.example.com/a.pdf' => '%PDF-1.4 a',
            'https://files.example.com/b.pdf' => '%PDF-1.4 b',
            'https://files.example.com/login' => '<!DOCTYPE html><html><body>Login</body></html>',
        ]);
    }

    /**
     * Replace network downloads with fixed contents.
     *
     * @param array $contents body by URL; URLs not listed fail
     */
    private function fake_downloads(array $contents): void {
        \core\di::set(materials::class, new class ($contents) extends materials {
            /** @var array body by URL */
            private array $contents;

            /**
             * Constructor.
             *
             * @param array $contents body by URL
             */
            public function __construct(array $contents) {
                $this->contents = $contents;
            }

            /**
             * Serve the fake body.
             *
             * @param string $url URL
             * @param string $path target file
             * @param int $maxbytes size limit
             * @return array
             */
            protected function fetch(string $url, string $path, int $maxbytes): array {
                if (!array_key_exists($url, $this->contents)) {
                    throw new \moodle_exception('downloadfailed', 'local_extsync', '', $url);
                }
                file_put_contents($path, $this->contents[$url]);
                return [200, ''];
            }
        });
    }

    /**
     * Call push_lesson with named arguments, as the web service layer would.
     *
     * @param array $args arguments by name
     * @return array cleaned result
     */
    private function push(array $args): array {
        $values = [];
        foreach (external::push_lesson_parameters()->keys as $name => $description) {
            $values[] = array_key_exists($name, $args) ? $args[$name] : $description->default;
        }
        $result = external::push_lesson(...$values);
        return external_api::clean_returnvalue(external::push_lesson_returns(), $result);
    }

    /**
     * Moodle XML with true/false questions.
     *
     * @param int $count number of questions
     * @param string $text question text
     * @return string
     */
    private function xml(int $count, string $text = '<p>Is it true?</p>'): string {
        $questions = '';
        for ($i = 1; $i <= $count; $i++) {
            $questions .= '<question type="truefalse"><name><text>Q' . $i . '</text></name>'
                . '<questiontext format="html"><text><![CDATA[' . $text . ']]></text></questiontext>'
                . '<idnumber>chk-' . $i . '</idnumber><defaultgrade>1</defaultgrade>'
                . '<answer fraction="100"><text>true</text><feedback><text>Yes</text></feedback></answer>'
                . '<answer fraction="0"><text>false</text><feedback><text>No</text></feedback></answer>'
                . '</question>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><quiz>' . $questions . '</quiz>';
    }

    /**
     * A full lesson: arguments for the first push.
     *
     * @return array
     */
    private function lesson(): array {
        return [
            'courseid' => $this->course->id,
            'name' => 'Fractions',
            'summary' => '<p>Goal</p>',
            'assignintro' => '<p>Do it</p>',
            'files' => [['name' => 'a.pdf', 'url' => 'https://files.example.com/a.pdf', 'description' => 'Worksheet']],
            'pages' => [['key' => 'leadin', 'name' => 'Lead-in', 'html' => '<p>Hello</p>', 'cmid' => 0]],
            'quizxml' => $this->xml(2),
        ];
    }

    /**
     * Arguments that push the same lesson again with the ids the first push returned.
     *
     * @param array $first result of the first push
     * @param array $overrides changed arguments
     * @return array
     */
    private function again(array $first, array $overrides = []): array {
        $args = $this->lesson();
        $args['sectionid'] = $first['sectionid'];
        $args['assigncmid'] = $first['assigncmid'];
        $args['filecmids'] = $first['filecmids'];
        $args['quizcmid'] = $first['quizcmid'];
        $args['pages'][0]['cmid'] = $first['pagecmids'][0]['cmid'];
        return array_merge($args, $overrides);
    }

    /**
     * Whether a course module exists.
     *
     * @param int $cmid course module id
     * @return bool
     */
    private function exists(int $cmid): bool {
        global $DB;
        return $DB->record_exists('course_modules', ['id' => $cmid, 'deletioninprogress' => 0]);
    }

    /**
     * Pushing the same lesson again reuses every module.
     */
    public function test_repush_is_idempotent(): void {
        global $DB;

        $first = $this->push($this->lesson());
        $this->assertSame(1, count($first['filecmids']));
        $this->assertSame(2, $first['questions']);
        $this->assertSame('', $first['error']);
        $modules = $DB->count_records('course_modules', ['course' => $this->course->id]);
        $sections = $DB->count_records('course_sections', ['course' => $this->course->id]);

        $second = $this->push($this->again($first));

        $this->assertSame($first['sectionid'], $second['sectionid']);
        $this->assertSame($first['assigncmid'], $second['assigncmid']);
        $this->assertSame($first['quizcmid'], $second['quizcmid']);
        $this->assertSame($first['pagecmids'], $second['pagecmids']);
        $this->assertSame($modules, $DB->count_records('course_modules', ['course' => $this->course->id]));
        $this->assertSame($sections, $DB->count_records('course_sections', ['course' => $this->course->id]));
        $this->assertFalse($this->exists($first['filecmids'][0]));
        $this->assertTrue($this->exists($second['filecmids'][0]));
    }

    /**
     * Teacher-created activities are never deleted or overwritten, whatever ids are sent.
     */
    public function test_teacher_activities_are_untouched(): void {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'content' => 'Mine']);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'name' => 'Mine']);
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $this->course->id]);

        $args = $this->lesson();
        $args['filecmids'] = [$resource->cmid, $page->cmid];
        $args['deletecmids'] = [$page->cmid, $assign->cmid, $resource->cmid];
        $args['assigncmid'] = $assign->cmid;
        $args['pages'][0]['cmid'] = $page->cmid;
        $result = $this->push($args);

        $this->assertTrue($this->exists($page->cmid));
        $this->assertTrue($this->exists($assign->cmid));
        $this->assertTrue($this->exists($resource->cmid));
        $this->assertSame('Mine', $DB->get_field('page', 'content', ['id' => $page->id]));
        $this->assertSame('Mine', $DB->get_field('assign', 'name', ['id' => $assign->id]));
        $this->assertNotEquals($assign->cmid, $result['assigncmid']);
        $this->assertNotEquals($page->cmid, $result['pagecmids'][0]['cmid']);
        $this->assertContains('notowned', array_column($result['warnings'], 'warningcode'));
    }

    /**
     * Owned modules are only deleted through the id list of their own type, and never while they
     * hold student work.
     */
    public function test_owned_deletion_rules(): void {
        global $DB;
        $first = $this->push($this->lesson());
        $pagecmid = $first['pagecmids'][0]['cmid'];

        // A page id in the resource list is not a resource.
        $this->push($this->again($first, ['filecmids' => [$first['filecmids'][0], $pagecmid]]));
        $this->assertTrue($this->exists($pagecmid));

        // An attempted quiz and a submitted assignment are protected.
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $quiz = $DB->get_record('course_modules', ['id' => $first['quizcmid']]);
        // Attempts started by a teacher are previews, which do not count.
        $this->setUser($student);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_attempt($quiz->instance, $student->id);
        $assign = $DB->get_record('course_modules', ['id' => $first['assigncmid']]);
        $DB->insert_record('assign_submission', ['assignment' => $assign->instance, 'userid' => $student->id,
            'status' => 'submitted', 'timecreated' => time(), 'timemodified' => time(), 'latest' => 1, 'attemptnumber' => 0]);

        $this->setUser($this->service);
        $args = $this->lesson();
        $args['makeassign'] = 0;
        $args['quizxml'] = '';
        $args['pages'] = [];
        $args['files'] = [];
        $args['sectionid'] = $first['sectionid'];
        $args['deletecmids'] = [$first['quizcmid'], $first['assigncmid'], $pagecmid];
        $result = $this->push($args);

        $this->assertTrue($this->exists($first['quizcmid']));
        $this->assertTrue($this->exists($first['assigncmid']));
        $this->assertFalse($this->exists($pagecmid));
        $codes = array_column($result['warnings'], 'warningcode');
        $this->assertContains('quizhasattempts', $codes);
        $this->assertContains('assignhassubmissions', $codes);
    }

    /**
     * Push the standard lesson into a unit, so that all its activities sit in an owned subsection.
     *
     * @return array [push result, the subsection's delegated section]
     */
    private function lesson_in_subsection(): array {
        global $DB;
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        $args = $this->lesson();
        $args['unitname'] = 'Unit 1';
        $result = $this->push($args);
        $this->assertNotEmpty($result['subsectioncmid']);
        $instance = $DB->get_field('course_modules', 'instance', ['id' => $result['subsectioncmid']]);
        $section = $DB->get_record(
            'course_sections',
            ['course' => $this->course->id, 'component' => 'mod_subsection', 'itemid' => $instance],
            '*',
            MUST_EXIST
        );
        return [$result, $section];
    }

    /**
     * Push the next lesson of the unit, asking to delete an earlier lesson's subsection.
     *
     * @param array $first result of the earlier push
     * @return array push result
     */
    private function delete_subsection(array $first): array {
        return $this->push([
            'courseid' => $this->course->id,
            'name' => 'Next lesson',
            'unitname' => 'Unit 1',
            'makeassign' => 0,
            'deletecmids' => [$first['subsectioncmid']],
        ]);
    }

    /**
     * Check that deleting a lesson's subsection is refused and leaves everything in it in place.
     *
     * @param callable $addcontent receives the lesson result and the delegated section, adds content
     * @return array result of the refused push
     */
    private function assert_subsection_kept(callable $addcontent): array {
        global $DB;
        [$first, $section] = $this->lesson_in_subsection();
        $addcontent($first, $section);
        $this->setUser($this->service);
        $where = 'section = ? AND deletioninprogress = 0';
        $before = $DB->get_fieldset_select('course_modules', 'id', $where, [$section->id]);
        $attempts = $DB->count_records('quiz_attempts');
        $posts = $DB->count_records('forum_posts');

        $result = $this->delete_subsection($first);

        $this->assertTrue($this->exists($first['subsectioncmid']));
        $this->assertEqualsCanonicalizing($before, $DB->get_fieldset_select('course_modules', 'id', $where, [$section->id]));
        $this->assertSame($attempts, $DB->count_records('quiz_attempts'));
        $this->assertSame($posts, $DB->count_records('forum_posts'));
        $this->assertContains('subsectionnotempty', array_column($result['warnings'], 'warningcode'));
        return $result;
    }

    /**
     * An empty owned subsection is deleted.
     */
    public function test_empty_subsection_is_deleted(): void {
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        $first = $this->push(['courseid' => $this->course->id, 'name' => 'Empty', 'unitname' => 'Unit 1', 'makeassign' => 0]);
        $this->assertNotEmpty($first['subsectioncmid']);

        $this->delete_subsection($first);

        $this->assertFalse($this->exists($first['subsectioncmid']));
    }

    /**
     * An owned subsection holding only unused activities the plugin created is deleted with them.
     */
    public function test_subsection_with_only_owned_unused_activities_is_deleted(): void {
        [$first] = $this->lesson_in_subsection();
        $inner = array_merge(
            [$first['assigncmid'], $first['quizcmid']],
            $first['filecmids'],
            array_column($first['pagecmids'], 'cmid')
        );

        $result = $this->delete_subsection($first);

        $this->assertFalse($this->exists($first['subsectioncmid']));
        foreach ($inner as $cmid) {
            $this->assertFalse($this->exists($cmid));
        }
        $this->assertNotContains('subsectionnotempty', array_column($result['warnings'], 'warningcode'));
    }

    /**
     * A subsection holding the plugin's activities and a teacher's activity is kept.
     */
    public function test_subsection_with_mixed_content_is_kept(): void {
        $this->assert_subsection_kept(function (array $first, \stdClass $section): void {
            $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => $section->section]);
        });
    }

    /**
     * A subsection holding an attempted quiz is kept.
     */
    public function test_subsection_with_attempted_quiz_is_kept(): void {
        $this->assert_subsection_kept(function (array $first): void {
            global $DB;
            $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            // Attempts started by a teacher are previews.
            $this->setUser($student);
            $quizid = $DB->get_field('course_modules', 'instance', ['id' => $first['quizcmid']]);
            $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_attempt($quizid, $student->id);
        });
    }

    /**
     * A subsection holding a submitted assignment is kept.
     */
    public function test_subsection_with_submitted_assignment_is_kept(): void {
        $this->assert_subsection_kept(function (array $first): void {
            global $DB;
            $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            $instance = $DB->get_field('course_modules', 'instance', ['id' => $first['assigncmid']]);
            $DB->insert_record('assign_submission', ['assignment' => $instance, 'userid' => $student->id,
                'status' => 'submitted', 'timecreated' => time(), 'timemodified' => time(), 'latest' => 1, 'attemptnumber' => 0]);
        });
    }

    /**
     * A subsection holding a forum with posts is kept.
     */
    public function test_subsection_with_forum_posts_is_kept(): void {
        $this->assert_subsection_kept(function (array $first, \stdClass $section): void {
            $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id,
                'section' => $section->section]);
            $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion(['course' => $this->course->id,
                'forum' => $forum->id, 'userid' => $student->id]);
        });
    }

    /**
     * A module type the plugin cannot classify is never deleted, even when recorded as the plugin's:
     * neither on its own nor inside a subsection.
     */
    public function test_unclassified_module_is_never_deleted(): void {
        $forumcmid = 0;
        $this->assert_subsection_kept(function (array $first, \stdClass $section) use (&$forumcmid): void {
            $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id,
                'section' => $section->section]);
            // A record the plugin never writes itself: a module type it does not create.
            \local_extsync\local\ownership::record((int)$this->course->id, (int)$forum->cmid, 'forum');
            $forumcmid = (int)$forum->cmid;
        });

        $result = $this->push(['courseid' => $this->course->id, 'name' => 'Again', 'makeassign' => 0,
            'deletecmids' => [$forumcmid]]);

        $this->assertTrue($this->exists($forumcmid));
        $this->assertContains('modulenotdeletable', array_column($result['warnings'], 'warningcode'));
    }

    /**
     * Without the right to add subsections, a lesson with a unit goes into one regular section and no unit section
     * is left behind.
     */
    public function test_unit_without_subsection_capability_creates_one_section(): void {
        global $DB;
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $contextid = \context_course::instance($this->course->id)->id;
        assign_capability('mod/subsection:addinstance', CAP_PROHIBIT, $roleid, $contextid, true);
        $sections = $DB->count_records('course_sections', ['course' => $this->course->id]);

        $result = $this->push(['courseid' => $this->course->id, 'name' => 'Plain lesson', 'unitname' => 'Unit 1',
            'makeassign' => 0]);

        $this->assertSame($sections + 1, $DB->count_records('course_sections', ['course' => $this->course->id]));
        $this->assertSame(0, $result['subsectioncmid']);
        $this->assertFalse($DB->record_exists('course_sections', ['course' => $this->course->id, 'name' => 'Unit 1']));
    }

    /**
     * The subsection holding the section a push goes into is not deleted, even when its id is sent in deletecmids.
     */
    public function test_subsection_holding_the_lesson_is_not_deleted(): void {
        [$first] = $this->lesson_in_subsection();
        $args = $this->again($first, ['deletecmids' => [$first['subsectioncmid']]]);

        $result = $this->push($args);

        $this->assertTrue($this->exists($first['subsectioncmid']));
        $this->assertTrue($this->exists($result['pagecmids'][0]['cmid']));
        $this->assertTrue($this->exists($result['quizcmid']));
    }

    /**
     * A teacher's summary that is only an image is not treated as empty.
     */
    public function test_media_only_summary_is_kept(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $section = course_create_section($this->course->id);
        $summary = '<p><img src="https://example.com/map.png" alt=""></p>';
        course_update_section($this->course, $section, ['summary' => $summary, 'summaryformat' => FORMAT_HTML]);
        $args = $this->lesson();
        $args['sectionid'] = $section->id;
        $args['renamesection'] = 0;

        $this->push($args);

        $this->assertStringContainsString('map.png', $DB->get_field('course_sections', 'summary', ['id' => $section->id]));
    }

    /**
     * When the assignment module is disabled, the lesson is refused before anything is created: no empty section is
     * left behind, however often the request is retried.
     */
    public function test_disabled_assignment_module_changes_nothing(): void {
        global $DB;
        \core\plugininfo\mod::enable_plugin('assign', 0);
        $sections = $DB->count_records('course_sections', ['course' => $this->course->id]);
        $modules = $DB->count_records('course_modules', ['course' => $this->course->id]);

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->push($this->lesson());
                $this->fail('A lesson with an assignment was pushed while assignments are disabled');
            } catch (\moodle_exception $e) {
                $this->assertSame('moduledisable', $e->errorcode);
            }
        }

        $this->assertSame($sections, $DB->count_records('course_sections', ['course' => $this->course->id]));
        $this->assertSame($modules, $DB->count_records('course_modules', ['course' => $this->course->id]));
    }

    /**
     * A material that cannot be downloaded keeps the existing material activities.
     */
    public function test_failed_download_keeps_old_materials(): void {
        $first = $this->push($this->lesson());
        $old = $first['filecmids'][0];

        $args = $this->again($first, ['files' => [
            ['name' => 'b.pdf', 'url' => 'https://files.example.com/b.pdf', 'description' => ''],
            ['name' => 'c.pdf', 'url' => 'https://files.example.com/missing.pdf', 'description' => ''],
        ]]);
        $result = $this->push($args);

        $this->assertSame([$old], $result['filecmids']);
        $this->assertTrue($this->exists($old));
        $this->assertContains('materialsnotreplaced', array_column($result['warnings'], 'warningcode'));
        $this->assertSame(1, count(get_fast_modinfo($this->course->id)->get_instances_of('resource')));
    }

    /**
     * A failure while creating the new materials removes the partial new set and keeps the old one.
     */
    public function test_partial_material_failure_rolls_back(): void {
        $first = $this->push($this->lesson());
        $old = $first['filecmids'][0];

        $args = $this->again($first, ['files' => [
            ['name' => 'b.pdf', 'url' => 'https://files.example.com/b.pdf', 'description' => ''],
            ['name' => 'c.pdf', 'url' => 'https://files.example.com/login', 'description' => ''],
        ]]);
        $result = $this->push($args);

        $this->assertSame([$old], $result['filecmids']);
        $resources = get_fast_modinfo($this->course->id)->get_instances_of('resource');
        $this->assertSame([$old], array_values(array_map(fn($cm) => (int)$cm->id, $resources)));
    }

    /**
     * Script in lesson content is removed before it is stored; ordinary HTML is kept.
     */
    public function test_html_is_sanitised(): void {
        global $DB;
        $script = '<p>Visible</p><script>alert("x")</script><img src="x" onerror="alert(1)">';
        $args = $this->lesson();
        $args['summary'] = $script;
        $args['assignintro'] = $script;
        $args['pages'][0]['html'] = $script;
        $args['quizxml'] = $this->xml(1, $script);

        $result = $this->push($args);

        $stored = [
            $DB->get_field('course_sections', 'summary', ['id' => $result['sectionid']]),
            $DB->get_field_sql('SELECT a.intro FROM {assign} a JOIN {course_modules} cm ON cm.instance = a.id
                                 WHERE cm.id = ?', [$result['assigncmid']]),
            $DB->get_field_sql('SELECT p.content FROM {page} p JOIN {course_modules} cm ON cm.instance = p.id
                                 WHERE cm.id = ?', [$result['pagecmids'][0]['cmid']]),
        ];
        $questions = $DB->get_fieldset_sql("SELECT q.questiontext FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                JOIN {context} ctx ON ctx.id = qc.contextid
               WHERE ctx.contextlevel = ? AND ctx.instanceid = ?", [CONTEXT_MODULE, $result['quizcmid']]);
        $this->assertCount(1, $questions);
        foreach (array_merge($stored, $questions) as $html) {
            $this->assertStringContainsString('<p>Visible</p>', $html);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('onerror', $html);
        }
    }

    /**
     * Updating an owned assignment or page keeps the settings a teacher changed.
     */
    public function test_update_keeps_teacher_settings(): void {
        global $DB;
        $first = $this->push($this->lesson());
        $assign = $DB->get_record('course_modules', ['id' => $first['assigncmid']]);
        $DB->set_field('assign', 'grade', 42, ['id' => $assign->instance]);
        $DB->set_field('assign', 'duedate', 1893456000, ['id' => $assign->instance]);
        set_coursemodule_visible($assign->id, 0);

        $this->push($this->again($first, ['assignname' => 'Renamed', 'assignintro' => '<p>New</p>']));

        $record = $DB->get_record('assign', ['id' => $assign->instance]);
        $this->assertSame('Renamed', $record->name);
        $this->assertSame('<p>New</p>', $record->intro);
        $this->assertEquals(42, $record->grade);
        $this->assertEquals(1893456000, $record->duedate);
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $assign->id]));
    }

    /**
     * Creating a section needs moodle/course:update; nothing is created without it.
     */
    public function test_section_requires_course_update(): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('moodle/course:update', CAP_PROHIBIT, $roleid, \context_course::instance($this->course->id)->id, true);
        $modules = $DB->count_records('course_modules', ['course' => $this->course->id]);
        $sections = $DB->count_records('course_sections', ['course' => $this->course->id]);

        try {
            $this->push($this->lesson());
            $this->fail('Section created without moodle/course:update');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_capability_string('moodle/course:update'), $e->getMessage());
        }
        $this->assertSame($modules, $DB->count_records('course_modules', ['course' => $this->course->id]));
        $this->assertSame($sections, $DB->count_records('course_sections', ['course' => $this->course->id]));
    }

    /**
     * Deleting an owned module needs moodle/course:manageactivities in its own context.
     */
    public function test_deletion_requires_module_capability(): void {
        global $DB;
        $first = $this->push($this->lesson());
        $pagecmid = $first['pagecmids'][0]['cmid'];
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(
            'moodle/course:manageactivities',
            CAP_PROHIBIT,
            $roleid,
            \context_module::instance($pagecmid)->id,
            true
        );

        $args = $this->again($first, ['pages' => [], 'deletecmids' => [$pagecmid]]);
        try {
            $this->push($args);
            $this->fail('Module deleted without the module capability');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_capability_string('moodle/course:manageactivities'), $e->getMessage());
        }
        $this->assertTrue($this->exists($pagecmid));
        $this->assertTrue($this->exists($first['filecmids'][0]));
    }

    /**
     * Creating a quiz needs the question and quiz editing capabilities.
     */
    public function test_quiz_requires_question_capability(): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('moodle/question:add', CAP_PROHIBIT, $roleid, \context_course::instance($this->course->id)->id, true);

        $this->expectException(\required_capability_exception::class);
        $this->push($this->lesson());
    }
}
