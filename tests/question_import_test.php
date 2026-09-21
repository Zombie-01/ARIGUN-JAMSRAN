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
 * Question content tests: what students are shown, not only what is stored.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\local\question_import
 */
final class question_import_test extends \advanced_testcase {
    /** @var string Script attempts written as HTML, with harmless content that must survive. */
    public const HTML_PAYLOAD = '<p><a href="javascript:alert(1)">h1</a> <a href="JaVaScRiPt:alert(2)">h2</a> '
        . '<a href=" javascript:alert(3)">h3</a> <a href="java&#x09;script:alert(4)">h4</a> '
        . '<a href="&#106;avascript:alert(5)">h5</a> <a href="vbscript:msgbox(6)">h6</a> '
        . '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCg3KTwvc2NyaXB0Pg==">h7</a> <img src="x" onerror="alert(8)"> '
        . '<svg onload="alert(9)"></svg><script>alert(10)</script> <a href="https://moodle.org/">Moodle</a> '
        . '<strong>bold</strong></p>';

    /** @var string Script attempts written as Markdown, with harmless formatting that must survive. */
    public const MARKDOWN_PAYLOAD = "[m1](javascript:alert(1))\n\n[m2](JaVaScRiPt:alert(2))\n\n[m3](java&#x09;script:alert(3))\n\n"
        . "[m4](&#106;avascript:alert(4))\n\n[m5][r]\n\n[r]: javascript:alert(5)\n\n<javascript:alert(6)>\n\n"
        . "![m7](javascript:alert(7))\n\n[m8](vbscript:msgbox(8))\n\n"
        . "[m9](data:text/html;base64,PHNjcmlwdD5hbGVydCg5KTwvc2NyaXB0Pg==)\n\n"
        . "<a href=\"javascript:alert(10)\">m10</a> <img src=\"x\" onerror=\"alert(11)\">\n\n"
        . "[Moodle](https://moodle.org/) **bold** *italic*";

    /** @var \stdClass course */
    private $course;

    /** @var \stdClass web service user */
    private $service;

    /**
     * Course with a teacher acting as the web service account.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->service = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->service);
    }

    /**
     * Import questions through push_exam.
     *
     * @param string $questions question elements
     * @return int quiz course module id
     */
    private function exam(string $questions): int {
        $values = [];
        $args = ['courseid' => $this->course->id, 'name' => 'Exam',
            'xml' => '<?xml version="1.0" encoding="UTF-8"?><quiz>' . $questions . '</quiz>'];
        foreach (external::push_exam_parameters()->keys as $name => $description) {
            $values[] = array_key_exists($name, $args) ? $args[$name] : $description->default;
        }
        $result = external_api::clean_returnvalue(external::push_exam_returns(), external::push_exam(...$values));
        return $result['quizcmid'];
    }

    /**
     * A multiple choice question with every text in one format.
     *
     * @param string $format Moodle XML format name
     * @param string $text the text
     * @return string question element
     */
    private function multichoice(string $format, string $text): string {
        $t = '<text><![CDATA[' . $text . ']]></text>';
        $f = ' format="' . $format . '"';
        return '<question type="multichoice"><name><text>MC</text></name>'
            . "<questiontext$f>$t</questiontext><generalfeedback$f>$t</generalfeedback>"
            . '<idnumber>mc</idnumber><single>true</single><shuffleanswers>0</shuffleanswers>'
            . "<answernumbering>abc</answernumbering><correctfeedback$f>$t</correctfeedback>"
            . "<incorrectfeedback$f>$t</incorrectfeedback>"
            . "<answer fraction=\"100\"$f>$t<feedback$f>$t</feedback></answer>"
            . '<answer fraction="0" format="html"><text>Other</text><feedback format="html"><text>No</text></feedback></answer>'
            . "<hint$f>$t</hint></question>";
    }

    /**
     * Everything Moodle displays for the questions of a quiz, as a student with a finished attempt.
     *
     * The rendered question is complemented with answer texts, answer feedback, hints and combined
     * feedback formatted through the question's own format_text(), because some of them are only
     * shown for particular responses.
     *
     * @param int $cmid quiz course module id
     * @return string[] HTML by description
     */
    private function displayed(int $cmid): array {
        global $DB, $PAGE;
        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $generator->create_attempt($quizid, $student->id);
        $generator->submit_responses($attempt->id, [], false, true);
        $PAGE->set_url('/mod/quiz/review.php', ['attempt' => $attempt->id]);
        $PAGE->set_context(\context_module::instance($cmid));

        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $shown = [];
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $shown["slot $slot"] = $qa->render(new \question_display_options(), $slot);
            $root = $qa->get_question();
            foreach (array_merge([$root], $root->subquestions ?? []) as $question) {
                foreach ($question->answers ?? [] as $answer) {
                    $shown["answer $answer->id"] = $root->format_text(
                        $answer->answer,
                        $answer->answerformat,
                        $qa,
                        'question',
                        'answer',
                        $answer->id
                    );
                    $shown["answer feedback $answer->id"] = $root->format_text(
                        $answer->feedback,
                        $answer->feedbackformat,
                        $qa,
                        'question',
                        'answerfeedback',
                        $answer->id
                    );
                }
                foreach (['correctfeedback', 'incorrectfeedback'] as $field) {
                    if (isset($question->$field)) {
                        $shown["$field $question->id"] = $root->format_text(
                            $question->$field,
                            $question->{$field . 'format'},
                            $qa,
                            'question',
                            $field,
                            $question->id
                        );
                    }
                }
                foreach ($question->hints ?? [] as $hint) {
                    $shown["hint $hint->id"] = $question->format_hint($hint, $qa);
                }
            }
        }
        $this->setUser($this->service);
        return $shown;
    }

    /**
     * Fail when HTML contains anything that runs script when displayed or followed.
     *
     * @param string $html displayed HTML
     * @param string $where description for failures
     */
    private function assert_no_active_content(string $html, string $where): void {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($doc->getElementsByTagName('*') as $element) {
            if (in_array(strtolower($element->nodeName), ['script', 'iframe', 'object', 'embed'])) {
                $this->assertStringNotContainsString('alert', $element->textContent, "$where: $html");
            }
            foreach ($element->attributes as $attribute) {
                // Browsers ignore control characters and spaces inside a URL scheme.
                $value = strtolower(preg_replace('/[\x00-\x20]+/', '', $attribute->value));
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $this->assertDoesNotMatchRegularExpression('/alert|msgbox/', $value, "$where: $html");
                }
                foreach (['javascript:', 'vbscript:', 'data:text/html'] as $scheme) {
                    $this->assertStringStartsNotWith($scheme, $value, "$where: {$attribute->name} in $html");
                }
            }
        }
    }

    /**
     * Anchors pointing at a URL, in HTML.
     *
     * @param string $html displayed HTML
     * @param string $url the URL
     * @return int
     */
    private function links_to(string $html, string $url): int {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $count = 0;
        foreach ($doc->getElementsByTagName('a') as $anchor) {
            $count += (int)($anchor->getAttribute('href') === $url);
        }
        return $count;
    }

    /**
     * Formats and the content each must be shown safely.
     *
     * @return array
     */
    public static function format_provider(): array {
        return [
            'markdown' => ['markdown', self::MARKDOWN_PAYLOAD],
            'html' => ['html', self::HTML_PAYLOAD],
            'moodle_auto_format' => ['moodle_auto_format', self::HTML_PAYLOAD],
            'plain_text' => ['plain_text', '<a href="javascript:alert(1)">p1</a> [p2](javascript:alert(2))'],
        ];
    }

    /**
     * No question text, answer, feedback or hint displays active script, in any text format; ordinary
     * links and formatting still work.
     *
     * @dataProvider format_provider
     * @param string $format Moodle XML format name
     * @param string $text content of every text of the question
     */
    public function test_displayed_question_content_is_safe(string $format, string $text): void {
        $cmid = $this->exam($this->multichoice($format, $text));

        $shown = $this->displayed($cmid);

        $this->assertNotEmpty($shown);
        foreach ($shown as $where => $html) {
            $this->assert_no_active_content($html, "$format $where");
        }
        $all = implode("\n", $shown);
        if ($format === 'plain_text') {
            // Shown as text, not as a link.
            $this->assertStringContainsString('&lt;a href=&quot;javascript:alert(1)&quot;&gt;p1&lt;/a&gt;', $all);
        } else {
            $this->assertGreaterThan(0, $this->links_to($shown['slot 1'], 'https://moodle.org/'));
            $this->assertStringContainsString('<strong>bold</strong>', $shown['slot 1']);
        }
        if ($format === 'markdown') {
            $this->assertStringContainsString('<em>italic</em>', $shown['slot 1']);
        }
    }

    /**
     * Converted texts are stored as HTML, so Moodle never converts them again without cleaning.
     */
    public function test_markdown_is_stored_as_cleaned_html(): void {
        global $DB;
        $cmid = $this->exam($this->multichoice('markdown', self::MARKDOWN_PAYLOAD));

        $question = $DB->get_record_sql("SELECT q.* FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               WHERE qc.contextid = ?", [\context_module::instance($cmid)->id], MUST_EXIST);

        $this->assertEquals(FORMAT_HTML, $question->questiontextformat);
        $this->assertEquals(FORMAT_HTML, $question->generalfeedbackformat);
        $this->assert_no_active_content($question->questiontext, 'stored question text');
        $this->assertStringContainsString('<strong>bold</strong>', $question->questiontext);
        foreach ($DB->get_records('question_answers', ['question' => $question->id]) as $answer) {
            $this->assertEquals(FORMAT_HTML, $answer->answerformat);
            $this->assertEquals(FORMAT_HTML, $answer->feedbackformat);
        }
    }

    /**
     * Drag and drop words, select missing words and numerical units keep some texts as plain strings; they are
     * stored and displayed safely too.
     */
    public function test_choices_and_units_are_safe(): void {
        global $DB;
        $payload = '<![CDATA[<b onmouseover="alert(1)">x</b><img src="x" onerror="alert(2)">]]>';
        $empty = '<generalfeedback format="html"><text></text></generalfeedback>'
            . '<correctfeedback format="html"><text></text></correctfeedback>'
            . '<partiallycorrectfeedback format="html"><text></text></partiallycorrectfeedback>'
            . '<incorrectfeedback format="html"><text></text></incorrectfeedback>';
        $questions = '<question type="ddwtos"><name><text>Drag</text></name>'
            . '<questiontext format="html"><text>Drag [[1]] here</text></questiontext>' . $empty
            . '<idnumber>dd</idnumber><shuffleanswers>0</shuffleanswers>'
            . '<dragbox><text>' . $payload . '</text><group>1</group></dragbox>'
            . '<dragbox><text>other</text><group>1</group></dragbox></question>'
            . '<question type="gapselect"><name><text>Select</text></name>'
            . '<questiontext format="html"><text>Pick [[1]] here</text></questiontext>' . $empty
            . '<idnumber>gs</idnumber><shuffleanswers>0</shuffleanswers>'
            . '<selectoption><text>' . $payload . '</text><group>1</group></selectoption>'
            . '<selectoption><text>other</text><group>1</group></selectoption></question>'
            . '<question type="numerical"><name><text>Number</text></name>'
            . '<questiontext format="html"><text>How far?</text></questiontext>'
            . '<generalfeedback format="html"><text></text></generalfeedback><idnumber>num</idnumber>'
            . '<answer fraction="100"><text>5</text><feedback format="html"><text></text></feedback>'
            . '<tolerance>0</tolerance></answer>'
            // Unit names are at most 50 characters in the database.
            . '<units><unit><multiplier>1</multiplier><unit_name><![CDATA[<b onmouseover="alert(3)">m</b>]]></unit_name></unit>'
            . '<unit><multiplier>1000</multiplier><unit_name>km</unit_name></unit></units>'
            . '<unitgradingtype>1</unitgradingtype><unitpenalty>0.1</unitpenalty><showunits>1</showunits>'
            . '<unitsleft>0</unitsleft></question>';
        $cmid = $this->exam($questions);

        $stored = array_merge(
            $DB->get_fieldset_sql('SELECT answer FROM {question_answers}'),
            $DB->get_fieldset_sql('SELECT unit FROM {question_numerical_units}')
        );
        foreach ($stored as $text) {
            $this->assertDoesNotMatchRegularExpression('/onmouseover|onerror/i', (string)$text);
        }
        $shown = $this->displayed($cmid);
        foreach ($shown as $where => $html) {
            $this->assert_no_active_content($html, "choices $where");
        }
    }

    /**
     * A question type that is not part of standard Moodle is refused, because its texts could not be cleaned.
     */
    public function test_non_standard_question_type_is_refused(): void {
        $format = new class extends \local_extsync\local\question_import {
            /**
             * Parse the question as an installed add-on question type would.
             *
             * @param mixed $data question XML
             * @param mixed $question partly parsed question
             * @param mixed $extra extra data
             * @param string $qtypehint question type named in the file
             * @return \stdClass
             */
            public function try_importing_using_qtypes($data, $question = null, $extra = null, $qtypehint = '') {
                return (object)['qtype' => $qtypehint, 'name' => 'Add-on', 'questiontext' => '<b onclick="alert(1)">x</b>',
                    'questiontextformat' => FORMAT_HTML];
            }
        };

        try {
            $format->readquestions(['<?xml version="1.0" encoding="UTF-8"?><quiz><question type="wordselect">'
                . '<name><text>Add-on</text></name><questiontext format="html"><text>x</text></questiontext>'
                . '</question></quiz>']);
            $this->fail('A question type that is not standard was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('questiontypenotsupported', $e->errorcode);
        }
    }

    /**
     * Cloze sub-questions, which the importer nests inside the question, are displayed safely too.
     */
    public function test_cloze_subquestions_are_safe(): void {
        $text = 'Pick {1:MCV:=<b onmouseover="alert(1)">c1</b>#<i onmouseover="alert(2)">f1</i>'
            . '~<a href="javascript:alert(3)">c2</a>#<a href="javascript:alert(4)">f2</a>} '
            . 'and {1:SHORTANSWER:=yes#<a href="javascript:alert(5)">f5</a>} '
            . '<a href="https://moodle.org/">Moodle</a>';
        $cmid = $this->exam('<question type="cloze"><name><text>Cloze</text></name>'
            . '<questiontext format="html"><text><![CDATA[' . $text . ']]></text></questiontext>'
            . '<generalfeedback format="html"><text></text></generalfeedback><idnumber>cz</idnumber></question>');

        $shown = $this->displayed($cmid);

        $this->assertGreaterThan(2, count($shown));
        foreach ($shown as $where => $html) {
            $this->assert_no_active_content($html, "cloze $where");
        }
        $this->assertStringContainsString('c1', $shown['slot 1']);
        $this->assertGreaterThan(0, $this->links_to($shown['slot 1'], 'https://moodle.org/'));
    }
}
