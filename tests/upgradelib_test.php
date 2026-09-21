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

use local_extsync\local\ownership;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/extsync/db/upgradelib.php');

/**
 * Upgrade steps: adoption of modules created by version 1.x, references of questions imported earlier.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_extsync_adopt_legacy_modules
 * @covers     ::local_extsync_backfill_question_refs
 * @covers     ::local_extsync_attribute_sole_integration
 * @covers     ::local_extsync_attribute_question_integrations
 */
final class upgradelib_test extends \advanced_testcase {
    /**
     * Log a course_module_created event the way the standard log store stores it.
     *
     * @param \stdClass $module module from the data generator
     * @param int $userid acting user
     * @param string $origin request origin
     */
    private function log_created(\stdClass $module, int $userid, string $origin): void {
        global $DB;
        $context = \context_module::instance($module->cmid);
        $DB->insert_record('logstore_standard_log', [
            'eventname' => '\\core\\event\\course_module_created', 'component' => 'core', 'action' => 'created',
            'target' => 'course_module', 'objecttable' => 'course_modules', 'objectid' => $module->cmid,
            'crud' => 'c', 'edulevel' => 1, 'contextid' => $context->id, 'contextlevel' => CONTEXT_MODULE,
            'contextinstanceid' => $module->cmid, 'userid' => $userid, 'courseid' => $module->course,
            'anonymous' => 0, 'timecreated' => time(), 'origin' => $origin,
        ]);
    }

    /**
     * Only modules the web service account created through the web service are adopted, once.
     */
    public function test_adopts_service_created_modules(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $serviceuser = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $service = $DB->get_record('external_services', ['component' => 'local_extsync'], '*', MUST_EXIST);
        $DB->insert_record('external_services_users', ['externalserviceid' => $service->id, 'userid' => $serviceuser->id,
            'timecreated' => time()]);

        $bywebservice = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $byteacher = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $bybrowser = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $unsupported = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->log_created($bywebservice, $serviceuser->id, 'ws');
        $this->log_created($byteacher, $teacher->id, 'ws');
        $this->log_created($bybrowser, $serviceuser->id, 'web');
        $this->log_created($unsupported, $serviceuser->id, 'ws');

        $this->assertSame(1, local_extsync_adopt_legacy_modules());
        $this->assertSame(0, local_extsync_adopt_legacy_modules());

        // Ownership is now integration-scoped: adoption attributes each module to the integration
        // of the account that created it, so the module is visible to that integration only.
        $this->setUser($serviceuser);
        $this->assertNotNull(ownership::get_cm($course->id, $bywebservice->cmid, 'page'));
        $this->assertNull(ownership::get_cm($course->id, $byteacher->cmid));
        $this->assertNull(ownership::get_cm($course->id, $bybrowser->cmid));
        $this->assertNull(ownership::get_cm($course->id, $unsupported->cmid));

        // Another account, even one that may edit the course, is not the owning integration.
        $this->setUser($teacher);
        $this->assertNull(ownership::get_cm($course->id, $bywebservice->cmid, 'page'));
    }

    /**
     * Questions recorded as imported get their Moodle ID number as reference, once; a lost ID number is not
     * guessed, and no other question gains a provenance record.
     */
    public function test_backfills_question_refs(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><quiz>';
        foreach (['chk-1', 'chk-2'] as $ref) {
            $xml .= '<question type="truefalse"><name><text>' . $ref . '</text></name><questiontext format="html">'
                . '<text>Statement</text></questiontext><idnumber>' . $ref . '</idnumber>'
                . '<answer fraction="100"><text>true</text></answer><answer fraction="0"><text>false</text></answer></question>';
        }
        $cmid = external::push_exam($course->id, 0, '', 0, 'Exam', '', $xml . '</quiz>')['quizcmid'];
        // The state before references were recorded; one question already lost its Moodle ID number.
        $DB->set_field('local_extsync_question', 'ref', null, ['cmid' => $cmid]);
        [$kept, $lost] = array_values($DB->get_records('local_extsync_question', ['cmid' => $cmid], 'questionid'));
        $entryid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $lost->questionid]);
        $DB->set_field('question_bank_entries', 'idnumber', null, ['id' => $entryid]);
        $category = local\question_import::default_category(\context_module::instance($cmid));
        $teacherquestion = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('truefalse', null, ['category' => $category->id, 'idnumber' => 'chk-9']);

        $this->assertSame(1, local_extsync_backfill_question_refs());
        $this->assertSame(0, local_extsync_backfill_question_refs());

        $this->assertSame('chk-1', $DB->get_field('local_extsync_question', 'ref', ['id' => $kept->id]));
        $this->assertNull($DB->get_field('local_extsync_question', 'ref', ['id' => $lost->id]));
        $this->assertFalse($DB->record_exists('local_extsync_question', ['questionid' => $teacherquestion->id]));
        $this->assertSame(2, $DB->count_records('local_extsync_question', ['cmid' => $cmid]));
    }

    /**
     * Authorise a user on this plugin's web service, the way an administrator does.
     *
     * @param int $userid the account
     */
    private function authorise_on_service(int $userid): void {
        global $DB;
        $service = $DB->get_record('external_services', ['component' => 'local_extsync'], '*', MUST_EXIST);
        $DB->insert_record('external_services_users', [
            'externalserviceid' => $service->id, 'userid' => $userid, 'timecreated' => time(),
        ]);
    }

    /**
     * Authorise a user on a service belonging to this plugin's former component name.
     *
     * This is what a site that ran local_selbe still has after local_extsync is installed beside it:
     * Moodle does not rename components, so the old service and its users stay exactly as they were.
     *
     * @param int $userid the account to authorise
     */
    private function authorise_on_legacy_service(int $userid): void {
        global $DB;
        $serviceid = $DB->insert_record('external_services', [
            'name' => 'Selbe lesson push', 'shortname' => 'selbe', 'enabled' => 1, 'restrictedusers' => 1,
            'component' => 'local_selbe', 'timecreated' => time(), 'timemodified' => time(),
            'downloadfiles' => 0, 'uploadfiles' => 0,
        ]);
        $DB->insert_record('external_services_users', [
            'externalserviceid' => $serviceid, 'userid' => $userid, 'timecreated' => time(),
        ]);
    }

    /**
     * An ownership row as version 2.0.0 wrote it: no integration, because there was no such column.
     *
     * @param \stdClass $module module from the data generator
     * @return int the row id
     */
    private function legacy_ownership_row(\stdClass $module): int {
        global $DB;
        return $DB->insert_record('local_extsync_module', [
            'cmid' => $module->cmid, 'courseid' => $module->course, 'modname' => 'page',
            'integrationid' => null, 'timecreated' => time(),
        ]);
    }

    /**
     * A row written before ownership was integration-scoped is attributed from the log, not skipped.
     *
     * This is the 2.0.0 -> 2.1.0 upgrade. Without attribution the row matches no integration, the
     * caller is told notowned, and the next push re-creates the lesson it already has.
     */
    public function test_attributes_rows_written_before_integration_ownership(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $serviceuser = $this->getDataGenerator()->create_user();
        $this->authorise_on_service($serviceuser->id);
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $rowid = $this->legacy_ownership_row($module);
        $this->log_created($module, $serviceuser->id, 'ws');

        // The state the upgrade starts from: the row exists but proves nothing.
        $this->setUser($serviceuser);
        $this->assertNull(ownership::get_cm($course->id, $module->cmid, 'page'));

        $this->assertSame(1, local_extsync_adopt_legacy_modules());
        $this->assertSame(0, local_extsync_adopt_legacy_modules());

        // Attributed in place: the same row, now owned. A second row would be the duplicate bug.
        $this->assertSame(1, $DB->count_records('local_extsync_module', ['cmid' => $module->cmid]));
        $this->assertNotNull($DB->get_field('local_extsync_module', 'integrationid', ['id' => $rowid]));
        $this->assertNotNull(ownership::get_cm($course->id, $module->cmid, 'page'));
    }

    /**
     * With the log rotated away and one authorised account, the remaining rows are still attributed.
     *
     * There is only one integration they can belong to, so this is a deduction, not a guess.
     */
    public function test_attributes_sole_integration_when_the_log_is_gone(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $serviceuser = $this->getDataGenerator()->create_user();
        $this->authorise_on_service($serviceuser->id);
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $rowid = $this->legacy_ownership_row($module);

        // No log evidence at all: adoption can prove nothing.
        $this->assertSame(0, local_extsync_adopt_legacy_modules());

        $this->assertSame(1, local_extsync_attribute_sole_integration());
        $this->assertSame(0, local_extsync_attribute_sole_integration());

        $this->setUser($serviceuser);
        $this->assertNotNull($DB->get_field('local_extsync_module', 'integrationid', ['id' => $rowid]));
        $this->assertNotNull(ownership::get_cm($course->id, $module->cmid, 'page'));
    }

    /**
     * With two authorised accounts and no evidence, nothing is attributed: a wrong owner is worse
     * than none. This is the case the attribution must refuse, not the case it must solve.
     */
    public function test_two_service_accounts_are_not_guessed_between(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $this->authorise_on_service($first->id);
        $this->authorise_on_service($second->id);
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $rowid = $this->legacy_ownership_row($module);

        $this->assertSame(0, local_extsync_attribute_sole_integration());

        $this->assertNull($DB->get_field('local_extsync_module', 'integrationid', ['id' => $rowid]));
        foreach ([$first, $second] as $user) {
            $this->setUser($user);
            $this->assertNull(ownership::get_cm($course->id, $module->cmid, 'page'));
        }
    }

    /**
     * A recorded question takes the integration of the quiz it was imported into, and a question
     * whose quiz stays unowned stays unowned with it.
     */
    public function test_questions_inherit_the_integration_of_their_quiz(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->authorise_on_service($teacher->id);
        $this->setUser($teacher);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><quiz><question type="truefalse">'
            . '<name><text>q-1</text></name><questiontext format="html"><text>Statement</text></questiontext>'
            . '<idnumber>q-1</idnumber><answer fraction="100"><text>true</text></answer>'
            . '<answer fraction="0"><text>false</text></answer></question></quiz>';
        $cmid = external::push_exam($course->id, 0, '', 0, 'Exam', '', $xml)['quizcmid'];

        // Back to the 2.0.0 state: rows exist, neither proves an owner.
        $DB->set_field('local_extsync_module', 'integrationid', null, ['cmid' => $cmid]);
        $DB->set_field('local_extsync_question', 'integrationid', null, ['cmid' => $cmid]);
        $context = \context_module::instance($cmid);
        $this->assertSame([], ownership::question_ids($context));

        $this->assertSame(0, local_extsync_attribute_question_integrations());
        $this->assertSame(1, local_extsync_attribute_sole_integration());
        $this->assertSame(1, local_extsync_attribute_question_integrations());
        $this->assertSame(0, local_extsync_attribute_question_integrations());

        $this->assertNotEmpty(ownership::question_ids($context));
    }

    /**
     * Modules created under this plugin's former component name are adopted, not left unowned.
     *
     * Renaming the component makes Moodle install a new plugin with empty ownership tables. If the
     * former component were not accepted as evidence, every module the site already had would be
     * reported notowned, the caller would take the create branch, and the next push would duplicate
     * the whole course while the originals stayed behind unmanaged (SI-20).
     */
    public function test_adopts_modules_created_under_the_former_component(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $legacyuser = $this->getDataGenerator()->create_user();
        $this->authorise_on_legacy_service($legacyuser->id);
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->log_created($module, $legacyuser->id, 'ws');

        // Nothing authorises this account on the new service, so only the former component can prove it.
        $this->assertSame(1, local_extsync_adopt_legacy_modules());
        local_extsync_attribute_sole_integration();

        $this->setUser($legacyuser);
        $cm = ownership::get_cm($course->id, $module->cmid, 'page');
        $this->assertNotNull($cm);
        $this->assertSame((int)$module->cmid, (int)$cm->id);
    }

    /**
     * A module created by an account with no service at all is never adopted.
     *
     * The former component widens the evidence filter; it must not remove it. A teacher who created
     * a page through a web service call of their own is not this plugin's object.
     */
    public function test_module_created_without_any_service_is_not_adopted(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_user();
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->log_created($module, $stranger->id, 'ws');

        $this->assertSame(0, local_extsync_adopt_legacy_modules());
    }
}
