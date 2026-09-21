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

use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Imports Moodle XML questions into a quiz's own question bank.
 *
 * Moodle renders question text, answers and feedback without cleaning them, and content from the external system can
 * be machine-generated, so every text is stored as cleaned HTML or as plain text.
 * Questions go through qformat_xml, Moodle's own importer, so the question bank tables are written
 * by the code that owns them.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_import extends \qformat_xml {
    /** @var \stdClass[] questions returned by the last readquestions() call */
    protected $parsed = [];

    /**
     * Parse the file and clean every HTML text of every question.
     *
     * @param array $lines file lines
     * @return array|false parsed questions
     */
    public function readquestions($lines) {
        // The core importer reads a malformed document with PHP warnings; refuse it cleanly instead.
        global $CFG;
        try {
            if (class_exists(\core\xml_parser::class)) {
                // Moodle 5.1 replaced xmlize() with this class.
                $parsed = (new \core\xml_parser())->parse(implode('', $lines), 0, 'UTF-8', true);
            } else {
                require_once($CFG->libdir . '/xmlize.php');
                $parsed = xmlize(implode('', $lines), 0, 'UTF-8', true);
            }
        } catch (\moodle_exception $e) {
            return false;
        }
        if (empty($parsed['quiz']['#']['question']) || !is_array($parsed['quiz']['#']['question'])) {
            return false;
        }

        $questions = parent::readquestions($lines);
        if (is_array($questions)) {
            $refs = [];
            $standard = \core_plugin_manager::standard_plugins_list('qtype') ?: [];
            foreach ($questions as $question) {
                if (($question->qtype ?? '') === 'category') {
                    continue;
                }
                // The cleaning below knows where standard question types keep their texts; other types could
                // keep displayed texts where it does not look.
                if (!in_array($question->qtype ?? '', $standard, true)) {
                    throw new moodle_exception('questiontypenotsupported', 'local_extsync', '', s($question->qtype ?? ''));
                }
                self::clean_question($question);
                // The external system identifies a question by its ID number. It is kept apart from the question, because
                // the importer drops an ID number that is already used in the category.
                $question->extref = trim((string)($question->idnumber ?? ''));
                if ($question->extref === '') {
                    continue;
                }
                $question->idnumber = $question->extref;
                if (\core_text::strlen($question->extref) > 100) {
                    throw new moodle_exception('questionreftoolong', 'local_extsync', '', s($question->extref));
                }
                // Refused rather than guessed: some databases compare Moodle ID numbers without case.
                $key = \core_text::strtolower($question->extref);
                if (isset($refs[$key])) {
                    throw new moodle_exception('duplicatequestionref', 'local_extsync', '', s($question->extref));
                }
                $refs[$key] = true;
            }
            $this->parsed = $questions;
        }

        return $questions;
    }

    /**
     * Let a question type read its own Moodle XML (drag and drop, select missing words, ordering...).
     *
     * The parent derives the method name import_from_<format> from the class name, which only works for
     * qformat_xml itself: for this class, those question types would not be imported at all.
     *
     * @param mixed $data the question XML
     * @param mixed $question question processed so far
     * @param mixed $extra format specific data
     * @param string $qtypehint question type named in the file
     * @return \stdClass|false parsed question, or false when no question type can read it
     */
    public function try_importing_using_qtypes($data, $question = null, $extra = null, $qtypehint = '') {
        $qtypes = \question_bank::get_all_qtypes();
        $hint = $qtypehint !== '' ? \question_bank::get_qtype($qtypehint, false) : null;
        if (is_object($hint)) {
            array_unshift($qtypes, $hint);
        }
        foreach ($qtypes as $qtype) {
            if (method_exists($qtype, 'import_from_xml') && ($parsed = $qtype->import_from_xml($data, $question, $this, $extra))) {
                return $parsed;
            }
        }

        return false;
    }

    /**
     * Import questions into the default category of a quiz context.
     *
     * @param \stdClass $course the course
     * @param \context_module $context the quiz context
     * @param string $xml questions in Moodle XML
     * @return array imported question id => the external system reference ('' when none), in import order
     * @throws moodle_exception when nothing could be imported
     */
    public static function import(\stdClass $course, \context_module $context, string $xml): array {
        $category = self::default_category($context);

        $path = make_request_directory() . '/questions.xml';
        file_put_contents($path, $xml);

        $format = new self();
        $format->setCategory($category);
        $format->setContexts([$context]);
        $format->setCourse($course);
        $format->setFilename($path);
        $format->setRealfilename('questions.xml');
        $format->setMatchgrades('nearest');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);

        // The importer prints its progress.
        ob_start();
        try {
            $ok = $format->importprocess();
        } finally {
            ob_end_clean();
        }

        // The importer lists exactly the questions it saved, which later proves the plugin created them.
        $parsedrefs = [];
        foreach ($format->parsed as $question) {
            if (!empty($question->id)) {
                $parsedrefs[(int)$question->id] = $question->extref ?? '';
            }
        }
        $new = [];
        foreach ($format->questionids as $questionid) {
            $new[(int)$questionid] = $parsedrefs[(int)$questionid] ?? '';
        }
        if (!$ok || !$new) {
            throw new moodle_exception('importfailed', 'local_extsync');
        }

        return $new;
    }

    /**
     * The default question category of a context, created when missing.
     *
     * @param \context $context the context
     * @return \stdClass question category
     */
    public static function default_category(\context $context): \stdClass {
        global $CFG;

        // Moodle 5.0 deprecated question_make_default_categories() in favour of this flag.
        if ((int)$CFG->branch >= 500) {
            return question_get_default_category($context->id, true);
        }

        return question_get_default_category($context->id) ?: question_make_default_categories([$context]);
    }

    /**
     * Make every displayed text of one parsed question safe, in place.
     *
     * clean_text() only understands HTML. Markdown or Moodle auto-format source that passes it
     * unchanged can still become a javascript: link when Moodle converts it for display, without
     * cleaning. So each text is converted to HTML the way format_text() displays it, cleaned, and
     * stored as HTML. Plain text is escaped whenever it is displayed and is kept as it is.
     *
     * @param \stdClass $question parsed question
     */
    protected static function clean_question(\stdClass $question): void {
        foreach (['questiontext', 'generalfeedback'] as $field) {
            // Without a format, Moodle would store the text as Moodle auto-format.
            if (isset($question->$field) && is_string($question->$field) && !isset($question->{$field . 'format'})) {
                $question->{$field . 'format'} = FORMAT_HTML;
            }
        }
        self::clean_value($question);

        // Texts kept as plain strings that some renderers still output as HTML: drag and drop and select missing
        // words choices, drag labels, and numerical or calculated units.
        foreach (['choices' => 'answer', 'drags' => 'label'] as $list => $key) {
            foreach ($question->$list ?? [] as $i => $item) {
                if (is_array($item) && isset($item[$key]) && is_string($item[$key])) {
                    $question->{$list}[$i][$key] = clean_text($item[$key], FORMAT_HTML);
                }
            }
        }
        foreach (['draglabel', 'unit'] as $list) {
            foreach ($question->$list ?? [] as $i => $item) {
                if (is_string($item)) {
                    $question->{$list}[$i] = clean_text($item, FORMAT_HTML);
                }
            }
        }
    }

    /**
     * Clean every text inside part of a parsed question, recursively.
     *
     * A text is a string property with a sibling "<name>format" property, or an array with "text"
     * and "format" keys; Cloze sub-questions nest both inside objects. Other strings, such as short
     * answer responses, are compared rather than displayed and are kept.
     *
     * @param array|\stdClass $value part of the parsed question
     * @return array|\stdClass
     */
    protected static function clean_value($value) {
        if (is_array($value) && isset($value['text']) && is_string($value['text'])) {
            [$value['text'], $format] = self::safe_text($value['text'], $value['format'] ?? FORMAT_HTML);
            if (array_key_exists('format', $value)) {
                $value['format'] = $format;
            }
            return $value;
        }
        $items = is_object($value) ? get_object_vars($value) : $value;
        foreach ($items as $name => $item) {
            if (is_object($value) && is_string($item) && isset($value->{$name . 'format'})) {
                [$value->$name, $value->{$name . 'format'}] = self::safe_text($item, $value->{$name . 'format'});
            } else if (is_array($item) || is_object($item)) {
                if (is_object($value)) {
                    $value->$name = self::clean_value($item);
                } else {
                    $value[$name] = self::clean_value($item);
                }
            }
        }

        return $value;
    }

    /**
     * A text as cleaned HTML, converted the way Moodle displays its format.
     *
     * @param string $text text in its original format
     * @param int|string $format FORMAT_* constant
     * @return array [text, format]
     */
    protected static function safe_text(string $text, $format): array {
        // The FORMAT_* constants are strings.
        $format = (string)(int)$format;
        if ($format === FORMAT_PLAIN) {
            return [$text, FORMAT_PLAIN];
        }
        if (trim($text) === '') {
            return ['', FORMAT_HTML];
        }
        if ($format === FORMAT_MARKDOWN) {
            $text = markdown_to_html($text);
        } else if ($format !== FORMAT_HTML) {
            $text = text_to_html($text);
        }

        return [clean_text($text, FORMAT_HTML), FORMAT_HTML];
    }
}
