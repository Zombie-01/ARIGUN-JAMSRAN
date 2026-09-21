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
 * Site settings for External Sync.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_extsync', new lang_string('pluginname', 'local_extsync'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configmixedhostiplist(
            'local_extsync/allowedhosts',
            new lang_string('allowedhosts', 'local_extsync'),
            new lang_string('allowedhosts_desc', 'local_extsync'),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            'local_extsync/maxmaterialsize',
            new lang_string('maxmaterialsize', 'local_extsync'),
            new lang_string('maxmaterialsize_desc', 'local_extsync'),
            \local_extsync\local\materials::DEFAULT_MAX_MB,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_extsync/createhidden',
            new lang_string('createhidden', 'local_extsync'),
            new lang_string('createhidden_desc', 'local_extsync'),
            0
        ));
    }
}
