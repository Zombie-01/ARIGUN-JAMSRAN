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

namespace local_extsync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_extsync\local\ownership;

/**
 * Privacy provider tests.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The integration table and the data sent to the external system are declared with described fields.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('local_extsync'));
        $items = $collection->get_collection();

        $this->assertCount(2, $items);
        $this->assertInstanceOf(database_table::class, $items[0]);
        $this->assertSame('local_extsync_integration', $items[0]->get_name());
        $this->assertSame(['userid', 'integrationkey', 'timecreated'], array_keys($items[0]->get_privacy_fields()));
        $this->assertInstanceOf(external_location::class, $items[1]);
        $this->assertSame('extsync', $items[1]->get_name());
        foreach (['userid', 'username', 'idnumber', 'email', 'timefinish', 'grade', 'questions'] as $field) {
            $this->assertArrayHasKey($field, $items[1]->get_privacy_fields());
        }

        $strings = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue($strings->string_exists($item->get_summary(), 'local_extsync'));
            foreach ($item->get_privacy_fields() as $identifier) {
                $this->assertTrue($strings->string_exists($identifier, 'local_extsync'));
            }
        }
    }

    /**
     * The account of an integration is found in the system context, exported, and deleted; what the
     * integration owned stays but is owned by no one, so it can never be changed through the web service.
     */
    public function test_integration_account(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $account = $generator->create_user();
        $other = $generator->create_user();
        $student = $generator->create_user();
        $integrationid = ownership::integration_for_user($account->id, true);
        $otherid = ownership::integration_for_user($other->id, true);
        foreach ([[1, $integrationid], [2, $otherid]] as [$cmid, $owner]) {
            $DB->insert_record('local_extsync_module',
                ['cmid' => $cmid, 'courseid' => 1, 'modname' => 'quiz', 'integrationid' => $owner]);
            $DB->insert_record('local_extsync_question', ['questionid' => $cmid, 'cmid' => $cmid, 'integrationid' => $owner]);
        }
        $system = \context_system::instance();

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($account->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid($student->id)->get_contextids());
        $userlist = new userlist($system, 'local_extsync');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$account->id, $other->id], $userlist->get_userids());
        $courselist = new userlist(\context_course::instance($generator->create_course()->id), 'local_extsync');
        provider::get_users_in_context($courselist);
        $this->assertEmpty($courselist->get_userids());

        $this->export_context_data_for_user($account->id, $system, 'local_extsync');
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_extsync')]);
        $this->assertSame('user-' . $account->id, $data->integrationkey);

        provider::delete_data_for_user(new approved_contextlist($account, 'local_extsync', [$system->id]));
        $this->assertFalse($DB->record_exists('local_extsync_integration', ['userid' => $account->id]));
        $this->assertNull($DB->get_field('local_extsync_module', 'integrationid', ['cmid' => 1]));
        $this->assertNull($DB->get_field('local_extsync_question', 'integrationid', ['cmid' => 1]));
        $this->assertEquals($otherid, $DB->get_field('local_extsync_module', 'integrationid', ['cmid' => 2]));
        $this->assertEquals($otherid, $DB->get_field('local_extsync_question', 'integrationid', ['cmid' => 2]));

        // The same account calling again gets a new integration that owns none of the old objects.
        $this->assertNotEquals($integrationid, ownership::integration_for_user($account->id, true));

        provider::delete_data_for_users(new approved_userlist($system, 'local_extsync', [$other->id]));
        $this->assertFalse($DB->record_exists('local_extsync_integration', ['userid' => $other->id]));
        $this->assertNull($DB->get_field('local_extsync_module', 'integrationid', ['cmid' => 2]));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertSame(0, $DB->count_records('local_extsync_integration'));
    }
}
