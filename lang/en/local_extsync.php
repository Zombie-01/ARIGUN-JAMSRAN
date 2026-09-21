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
 * English strings for External Sync.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allowedhosts'] = 'Allowed material hosts';
$string['allowedhosts_desc'] = 'Host names that lesson material files may be downloaded from, one per line, for example <code>files.example.com</code> or <code>*.example.com</code>. Only HTTPS URLs on these hosts are downloaded. While this list is empty, lessons are still pushed but their material files are not.';
$string['assignhassubmissions'] = 'Assignment {$a} was not deleted because it has student submissions or grades.';
$string['countmismatch'] = 'The request contained {$a->received} students but {$a->expected} were expected. The list may have been truncated, so nothing was changed.';
$string['countrequired'] = 'Student synchronisation requires expectedcount, so that a truncated list can be detected. Nothing was changed.';
$string['createhidden'] = 'Create activities hidden';
$string['createhidden_desc'] = 'New activities pushed from the external system stay hidden from students until a teacher shows them. Existing activities keep their visibility.';
$string['defaultexamsection'] = 'Exam';
$string['downloadfailed'] = 'The material file could not be downloaded: {$a}';
$string['downloadhtml'] = 'The material URL returned a web page instead of a file: {$a}';
$string['downloadredirect'] = 'Too many redirects while downloading: {$a}';
$string['downloadtoolarge'] = 'The material file is larger than the maximum material size: {$a}';
$string['duplicatequestionref'] = 'The questions were not imported: the ID number {$a} is used by more than one question. ID numbers are compared ignoring case and surrounding spaces.';
$string['emptyexam'] = 'The exam contains no questions.';
$string['emptystudentlist'] = 'The student list is empty. An empty list never removes students, so nothing was changed.';
$string['examhasattempts'] = 'This exam already has {$a} attempt(s), so its questions cannot be replaced. Create a new exam instead.';
$string['examinprogress'] = 'Another replacement of this exam is still running. Nothing was changed; try again later.';
$string['hostnotallowed'] = 'Material host {$a} is not in the allowed material hosts list.';
$string['httpsrequired'] = 'Material URLs must use HTTPS: {$a}';
$string['importfailed'] = 'The questions could not be imported.';
$string['manualenroldisabled'] = 'The manual enrolment method of this course is disabled, so nothing was changed. Enable it in the course to synchronise students.';
$string['manualenrolmissing'] = 'Manual enrolment is not available in this course.';
$string['materialsnotreplaced'] = 'The lesson materials were not replaced, and the existing material activities were kept: {$a}';
$string['maxmaterialsize'] = 'Maximum material size (MB)';
$string['maxmaterialsize_desc'] = 'Material files larger than this are not downloaded.';
$string['modulenotdeletable'] = 'Course module {$a} was not deleted because External Sync does not delete activities of this type.';
$string['modulenotdeleted'] = 'Course module {$a} could not be deleted and was kept.';
$string['nostudentrole'] = 'The manual enrolment method in this course has no default role.';
$string['notowned'] = 'Course module {$a} is not an activity created by External Sync in this course (it may have been deleted), so it was not changed.';
$string['pluginname'] = 'External Sync';
$string['privacy:metadata:extsync'] = 'When the web service account requests quiz results, they are sent to the external system connected to this site. External Sync does not store personal data in Moodle.';
$string['privacy:metadata:extsync:email'] = 'The student\'s email address, only when the web service account is allowed to see it.';
$string['privacy:metadata:extsync:grade'] = 'The grade of the student\'s last finished attempt, as a percentage.';
$string['privacy:metadata:extsync:idnumber'] = 'The student\'s ID number, only when the web service account is allowed to see it.';
$string['privacy:metadata:extsync:questions'] = 'The score, mark and maximum mark of each question created by the external system in that attempt.';
$string['privacy:metadata:extsync:timefinish'] = 'The time the attempt was finished.';
$string['privacy:metadata:extsync:userid'] = 'The Moodle user ID of the student.';
$string['privacy:metadata:extsync:username'] = 'The student\'s username, only when the web service account is allowed to see it.';
$string['questionreftoolong'] = 'The questions were not imported: the ID number {$a} is longer than 100 characters.';
$string['questiontypenotsupported'] = 'The questions were not imported: {$a} is not a standard Moodle question type.';
$string['quizhasattempts'] = 'Quiz {$a} was not deleted because students have attempted it.';
$string['removaltoolarge'] = 'This sync would remove {$a->remove} students, after {$a->removed} removed in the last 7 days, from a class of {$a->total}. External Sync removes at most half of a class within 7 days, so nothing was changed. Remove larger groups in Moodle.';
$string['rolenotassignable'] = 'The web service account cannot assign the enrolment role in this course.';
$string['settingsnotapplied'] = 'Exam {$a} already exists and its questions were not replaced, so the dates, time limit, attempts and description sent were not applied.';
$string['subsectionnotempty'] = 'Subsection {$a} was not deleted because it contains activities that were not created by External Sync or that hold student work. Deleting a subsection deletes everything in it.';
$string['subsectionfailed'] = 'The unit subsection could not be created, so the lesson was placed in a regular section: {$a}';
$string['syncinprogress'] = 'Another student synchronisation of this course is still running. Nothing was changed; try again later.';
$string['toomanystudents'] = 'At most {$a} students can be sent in one request.';
$string['unenrolnotallowed'] = 'Students cannot be unenrolled from the manual enrolment method in this course.';
