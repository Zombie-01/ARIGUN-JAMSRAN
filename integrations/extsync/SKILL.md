---
name: moodle-extsync
description: Push lessons, pages and quizzes into a Moodle course, enrol students and read quiz grades through the External Sync (local_extsync) Moodle plugin. Use when the user wants to publish course content to Moodle, create or update a Moodle quiz from questions, sync a class list, or fetch quiz results.
---

# Moodle External Sync

The Moodle site runs the `local_extsync` plugin. Everything goes through `extsync.py` in this folder,
which does Moodle's form encoding; never build the REST request by hand.

## Setup

Two environment variables, both from the Moodle administrator:

* `EXTSYNC_URL` — site root, e.g. `https://moodle.example.edu`
* `EXTSYNC_TOKEN` — a web service token for the **External Sync push** service

If either is missing, ask the user for it. Never print the token back.

## Calling

Write the parameters as JSON to a file and run:

```
python extsync.py <function> params.json
```

`python extsync.py --tools claude` prints every parameter with its meaning. The functions:

| Function | Required | Use |
|---|---|---|
| `push_lesson` | `courseid`, `name` | section + assignment, `pages`, `files`, `quizzes` |
| `push_exam` | `courseid`, `name`, `xml` | one quiz from Moodle XML questions |
| `sync_students` | `courseid`, `students`, `expectedcount` | enrol existing users |
| `fetch_grades` | `courseid`, `quizcmid` | attempts and marks |

## Rules that avoid damage

* **Updating, not duplicating.** Every push returns ids (`sectionid`, `assigncmid`, `quizcmid`, page and
  quiz `cmid`s). Keep them and send them back on the next push of the same content; sending 0 creates a
  new copy.
* **Warnings matter.** A `notowned` warning means the id belongs to something this plugin did not create
  for this token; the plugin created a new item instead. Report it to the user.
* **Deleting** (`deletecmids`) and **`removemissing`** remove things. Confirm with the user first.
* **Questions** are Moodle XML. Give each question an `<idnumber>` so grades can be matched later.
  An exam that students have attempted cannot have its questions replaced.
* **Files** are downloaded by Moodle from HTTPS URLs on hosts the administrator allowed; others are skipped
  with a warning.
* `sync_students` only enrols users who already exist in Moodle; `expectedcount` must equal the number sent.

## Example

```json
{"courseid": 5, "name": "Photosynthesis",
 "pages": [{"key": "intro", "name": "Introduction", "html": "<p>Plants make food from light.</p>"}],
 "quizxml": "<?xml version=\"1.0\"?><quiz><question type=\"truefalse\"><name><text>Q1</text></name><questiontext format=\"html\"><text>Plants need light.</text></questiontext><idnumber>ph-1</idnumber><answer fraction=\"100\"><text>true</text></answer><answer fraction=\"0\"><text>false</text></answer></question></quiz>",
 "quizname": "Check"}
```
