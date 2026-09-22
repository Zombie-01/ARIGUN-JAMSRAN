# ARIGUN External Sync

ARIGUN External Sync (`local_extsync`) lets an external system publish course content and exams into Moodle, keep
manual enrolments aligned with its own class lists, and read quiz results back — without overwriting work that
teachers created.

The plugin is a **receiver**. It holds no credentials, stores no address of any external system and never
initiates a connection. It acts only when something calls it through Moodle's web services with a token the site
administrator issued. Any system that can make an authenticated REST call to Moodle can drive it; ARIGUN is the
initial reference integration, not a requirement.

## What it does

| Web service function | What it does |
|---|---|
| `local_extsync_push_lesson` | Creates or updates one lesson in a course: a section (or a subsection of a unit section), an assignment, material files, pages and quizzes. |
| `local_extsync_push_exam` | Creates a quiz-only exam, or replaces the questions of an exam nobody has attempted yet. |
| `local_extsync_sync_students` | Enrols existing Moodle users through manual enrolment, and optionally unenrols students no longer in the external class. |
| `local_extsync_fetch_grades` | Returns the last finished attempt of each student on a quiz, question by question. |

The plugin also adds a pre-built external service, **External Sync push**, containing these functions plus
`core_course_search_courses` and `core_course_get_contents` (used by the external system's course and section pickers).

## What it does not do

* It does **not** create, update, suspend or delete user accounts. Accounts come from your usual process
  (authentication plugin, upload, SIS integration).
* It does **not** create courses or categories.
* It does **not** touch cohort, self, database or any enrolment method other than manual enrolment.
* It does **not** write grades into the gradebook; grades stay in Moodle and are only read.
* It does **not** change, move or delete activities that teachers created. Only activities created by this plugin
  are updated or removed.
* It has no user interface for teachers; everything happens from the external system.

## Requirements

* **An external system to call it.** The plugin has no interface of its own and does nothing until some system
  calls it with a web service token you issue. The request contract is documented below, so any system can be
  that caller.
* **Moodle 4.5 (LTS) to 5.2.** Declared as `$plugin->supported = [405, 502]`.
* **PHP** as required by your Moodle version — Moodle's own minimum (`admin/environment.xml`): 4.5 needs PHP 8.1+,
  5.0 and 5.1 need 8.2+, 5.2 needs 8.3+. The plugin adds no requirement of its own and was runtime-tested on
  PHP 8.3 only.
* **Databases**: the plugin uses only portable Moodle database APIs. Release 3.0.0 was runtime-tested on
  Moodle 4.5, 5.0, 5.1 and 5.2 with PostgreSQL 16 and MariaDB 10.11, on MySQL 8.4 with Moodle 5.0–5.2 and on
  MySQL 8.0 with Moodle 4.5 (Moodle 5.x itself requires MySQL 8.4).
* Web services and the REST protocol enabled.
* Outbound HTTPS from the Moodle server to the host that serves lesson material files, and that host listed in
  **Allowed material hosts**. Without it lessons are pushed without their files; nothing else is affected.
* Optional: subsections (`mod_subsection`, Moodle 4.5+) for lessons grouped under units.

## Installation

1. Install the plugin ZIP from *Site administration → Plugins → Install plugins*, or extract it to
   `local/extsync` (on Moodle 5.1 and later: `public/local/extsync`).
2. Visit *Site administration → Notifications* and complete the upgrade.
3. Configure the plugin at *Site administration → Plugins → Local plugins → ARIGUN External Sync* (see
   [Settings](#settings)).

### Coming from an earlier version of this plugin

Moodle has no component rename. Version 3.0.0 is the component `local_extsync`, where 1.x and 2.x were
`local_selbe`, so Moodle installs it as a **new plugin** rather than upgrading the old one. What that means in
practice:

* **Install this plugin before you uninstall the old one.** A 2.x site keeps its ownership, question references
  and removal history in `local_selbe_*` tables; `db/install.php` copies them row for row, and only reads them.
  Once the old plugin is uninstalled those tables are gone and cannot be copied any more.
* **Existing activities are adopted at install time.** With empty ownership tables the plugin would treat every
  activity it had previously created as someone else's, report `notowned`, and create a second copy of every
  lesson on the next push. Besides the copy above, `db/install.php` reconstructs ownership from the standard log —
  `course_module_created` events raised through web services by an account authorised on, or holding a token for,
  this plugin's service under either its current or its former component name. Activities it cannot prove it
  created are left alone; the external system creates fresh copies of those on the next push.
* **Tokens do not carry over.** The new component installs its own service with no tokens. Issue a token against
  **External Sync push** and switch the caller to the `local_extsync_*` function names in the same window.
  **Issue it to the same user account the old token belonged to.** Ownership belongs to that account; a token for a
  different account owns nothing, is told `notowned`, and the next push creates every activity again.
* **The old plugin stays installed** until you uninstall it, and its tables remain untouched. That is the
  rollback path; verify the new one works before removing it.
* **A site that never ran the old plugin is unaffected** — there is no evidence to adopt and nothing happens.
* Material files are only downloaded from hosts in **Allowed material hosts**. Until you fill in that setting,
  lessons are pushed without their files.
* `local_extsync_sync_students` now requires `expectedcount` on every request, and removes at most half of a class
  within 7 days.
* `force` on `local_extsync_push_exam` is ignored: questions of an attempted exam are never replaced.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| Allowed material hosts | empty | Host names material files may be downloaded from, one per line. Wildcards such as `*.example.com` are allowed. Only HTTPS URLs are downloaded. Empty means no material is downloaded. |
| Maximum material size (MB) | 50 | Larger files are not downloaded. |
| Create activities hidden | off | New activities are hidden from students until a teacher shows them. |

The Moodle web service token is managed by Moodle. The plugin stores no credentials of any external system.

## Web service setup

1. *Site administration → Advanced features*: enable **web services**.
2. *Site administration → Server → Web services → Manage protocols*: enable **REST**.
3. Create a dedicated user for the external system, for example `extsync-connector`. Do not use an administrator account. Make sure
   the account is not flagged to change its password, otherwise every call fails.
4. Create a role for it (see [Least-privilege setup](#least-privilege-setup)) and assign it.
5. *Site administration → Server → Web services → External services → External Sync push → Authorised users*:
   add the connector account.
6. *Site administration → Server → Web services → Manage tokens*: create a token for that user and the
   **External Sync push** service. Restrict it to the IP addresses of the external system when you know them.
7. Enter your site URL and the token in the external system.

To test the token:

```
curl "https://moodle.example.com/webservice/rest/server.php" \
  -d wstoken=TOKEN -d moodlewsrestformat=json \
  -d wsfunction=core_course_search_courses -d criterianame=search -d criteriavalue= -d page=0 -d perpage=1
```

### Without writing a client: scripts and AI assistants

The repository's [`integrations/`](integrations/) folder (not part of the plugin ZIP) holds a one-file Python client
that calls all four functions from JSON, a Claude skill, and tool definitions for Claude, OpenAI and Gemini, so a
script or an AI assistant can push lessons and exams with only the site URL and a token.

## Required capabilities

Every function checks the same capabilities Moodle's own interface requires for the same action, in the same
context, before anything is changed.

| Capability | Context | Needed for |
|---|---|---|
| `webservice/rest:use` | system | Calling any function through REST |
| `moodle/course:view` | course or category | Reaching courses the connector account is not enrolled in |
| `moodle/course:manageactivities` | course and each activity | Creating, updating, moving and deleting activities |
| `moodle/course:update` | course | Creating sections and changing section names and summaries |
| `mod/assign:addinstance` | course | Creating assignments |
| `mod/resource:addinstance` | course | Creating material files |
| `mod/page:addinstance` | course | Creating lesson pages |
| `mod/quiz:addinstance` | course | Creating quizzes and exams |
| `mod/subsection:addinstance` | course | Units shown as subsections (optional; without it lessons go into regular sections) |
| `mod/quiz:manage` | course / quiz | Adding and replacing quiz questions |
| `moodle/question:add` | course / quiz | Importing questions |
| `mod/quiz:viewreports` | quiz | Reading results |
| `moodle/site:viewuseridentity` | quiz | Receiving identity fields with results |
| `moodle/site:accessallgroups` | quiz | Reading results of all groups in separate-groups quizzes |
| `enrol/manual:enrol` | course | Enrolling students |
| `enrol/manual:unenrol` | course | Removing students (`removemissing`) |
| `moodle/course:enrolconfig`, `enrol/manual:config` | course | Only when a course has no manual enrolment method yet |

The role must also be allowed to assign the role used by the manual enrolment method (normally *Student*):
*Site administration → Users → Permissions → Define roles → Allow role assignments*.

## Least-privilege setup

* Create a custom role, for example *External connector*, with only the capabilities above.
* Allow it in the **Category** and **Course** context types, and assign it only in the categories whose courses
  the external system should manage, instead of at system level. Grant `webservice/rest:use` through a separate system role that
  contains nothing else.
* Keep the service's **Authorised users only** restriction, restrict the token to the external system's IP addresses, and revoke
  it when a school stops using the external system.
* Leave Moodle's `curlsecurityblockedhosts` and `curlsecurityallowedport` settings in place. The plugin relies on
  them in addition to its own host allowlist.

## Student synchronisation

`local_extsync_sync_students(courseid, students[], removemissing, expectedcount)`

* Each row carries `idnumber`, `username` and/or `email`. Every key given is looked up; the keys that find an account
  must all find the same one. ID numbers and emails are compared case-insensitively on every database.
* Keys that find different accounts (for example the ID number of one student and the email of another) make the row
  ambiguous: none of those accounts is enrolled or removed. A key that finds no account is ignored when another key
  finds one. The guest account is never matched.
* A disabled manual enrolment method is refused (`manualenroldisabled`): students enrolled into it would get no access.
* Exactly one matching account: the user is enrolled with the manual enrolment method's default role.
* No matching account: the key is returned in `unmatched`. No account is created.
* Several matching accounts: the key is returned in `ambiguous`, and none of those accounts is enrolled or removed.
* Suspended accounts are matched like other accounts (Moodle already blocks their sign-in).
* Running the same list again changes nothing.
* Every request must send `expectedcount`, the number of rows sent. A request without it, or whose rows differ from it
  (a truncated request), is refused and nothing changes.
* With `removemissing = 1`, students enrolled manually with the enrolment role and not in the list are unenrolled,
  except users who hold any other role in the course. Unenrolling deletes the student's gradebook grades in that
  course (Moodle core behaviour; quiz attempts are kept). The request is refused, and nothing changes, when:
  * the list is empty;
  * the students removed in the last 7 days, this request included, would exceed half of the class as recorded by
    the earliest synchronisation of those 7 days. Students enrolled in between do not raise the limit. Remove larger
    groups in Moodle itself.
* Requests for the same course run one at a time; a request that waits more than 5 seconds for another one is
  refused (`syncinprogress`) and can be retried.
* Send `courseid`, `removemissing` and `expectedcount` before the `students` rows, so that PHP input truncation
  cuts rows (detected by `expectedcount`) rather than the flags.

### Batch size

Send at most **1000 students per request**. Larger lists are refused. Moodle's REST server receives parameters
as form fields, and PHP silently drops fields beyond `max_input_vars` (Moodle requires at least 5000); three fields
per student keeps 1000 students safely below that limit. `removemissing` needs the complete class in one request,
so it is available for classes of up to 1000 manually enrolled students.

## Lesson synchronisation

`local_extsync_push_lesson` receives the lesson and the ids returned by the previous push, and returns the ids to
store for the next one.

* Activities are reused only when the id belongs to an activity this plugin created, in the same course, of the
  expected type. Other ids are ignored and reported in `warnings` (`notowned`).
* New activities use your site's default settings for assignments, quizzes, pages and files. Existing activities
  keep every setting teachers changed; only names, descriptions and page content follow the external system.
* Material files are downloaded **before** anything changes. New material activities are created first; the old
  ones are removed only after all new ones exist. If a file cannot be downloaded or published, the existing
  materials are kept and a `materialsnotreplaced` warning is returned. An old material activity that cannot be
  removed is kept, reported with `modulenotdeleted`, and its id stays in the returned list.
* `deletecmids` removes stale activities created by the plugin: pages, files, quizzes, assignments and subsections.
  A quiz with attempts or an assignment with submissions or grades is never deleted (`quizhasattempts`,
  `assignhassubmissions` warnings). Deleting a subsection deletes everything inside it, so a subsection is only
  deleted when every activity inside was created by the plugin and could be deleted on its own
  (`subsectionnotempty` warning otherwise). Other activity types are never deleted (`modulenotdeletable`).
* HTML from the external system (summaries, descriptions, page content) is cleaned with Moodle's HTML cleaner before it is stored.
  Question texts, answers, feedback and hints in Markdown or Moodle auto-format are converted to HTML, cleaned and
  stored as HTML; plain text is kept and escaped by Moodle when displayed. Drag and drop choices, drag labels, select
  missing words choices and units are cleaned as HTML too. Question types that are not part of standard Moodle are
  refused, because their texts could not be located for cleaning.
* Steps that fail independently (a page, a quiz) are reported in `error`, and a stale activity that cannot be deleted
  in `warnings`; the rest of the lesson is kept. A failure of the assignment or of moving modules aborts the call;
  repeat it with the ids of the previous successful push.

## Exam synchronisation

`local_extsync_push_exam`

* Without an existing exam id, a new section (named *Exam* unless `sectionname` is given) and a quiz are created,
  with `timeopen`, `timeclose`, `timelimit`, `attempts` and the description applied.
* With the id of an exam the plugin created, `replace = 1` replaces its questions only if **nobody has attempted it**
  (including attempts still in progress). The old questions are removed and the new ones imported in one database
  transaction, so a failed import leaves the exam unchanged. Attempts are counted again just before the replacement
  is saved, and an attempt started in the meantime rolls it back. Still, replace exams before students can start
  them: an attempt being started at that very instant cannot be detected.
* A replaced question is deleted from the question bank only if the plugin imported it for this exam, it is still
  in the exam's own question bank, nothing else uses it, and the connector account may edit it. Questions written by
  teachers, and questions imported by earlier plugin versions (which recorded no provenance), are never deleted and
  stay in the exam's question bank.
* Replacements of the same exam run one at a time; a request that waits more than 5 seconds for another one is
  refused (`examinprogress`) and can be retried.
* Each question's ID number is the external system's reference for it. The plugin records the reference with the question it
  imported, so it survives replacement even when Moodle cannot give the new question that ID number (because a kept
  old question still holds it). ID numbers must be unique within one exam, ignoring case and surrounding spaces, and
  at most 100 characters; otherwise the questions are not imported.
* Dates, time limit, attempts and description of an existing exam are changed through Moodle's module update API
  (calendar events updated, other quiz settings kept) only when its questions are replaced. Sent with `replace = 0`,
  they are not applied and a `settingsnotapplied` warning is returned.
* Only Moodle's standard question types are imported; other types are refused (`questiontypenotsupported`).

## Grade retrieval

`local_extsync_fetch_grades(courseid, quizcmid)` returns, for each student, the **last finished attempt**: grade as a
percentage (`null` while a question such as an essay still needs grading) and, for each question the external system imported with an ID number, that ID number as `ref` with the fraction, mark
and maximum mark. The reference follows the question through new versions a teacher saves. Questions the plugin did
not import are skipped, even when a teacher gave them the same ID number. For exams created by plugin versions that
recorded no provenance, questions are identified by their current Moodle ID numbers, as before.

* `idnumber`, `username` and `email` are only filled when *Show user identity* (`showuseridentity`) includes the
  field and the connector account has `moodle/site:viewuseridentity`, as in the quiz reports. Configure these so the external system can
  match students; otherwise the fields are empty and only the Moodle user id is returned.
* In separate-groups quizzes, callers without `moodle/site:accessallgroups` only receive their groups' students.
* Only current participants are reported, as in the quiz reports: users enrolled in the course with
  `mod/quiz:attempt` or `mod/quiz:reviewmyattempts`, including suspended enrolments. Attempts of users who were
  unenrolled or deleted are not returned.
* A hidden quiz, or one the connector account cannot access, is only read when the connector account may see it
  (`moodle/course:viewhiddenactivities` for hidden quizzes), as in the quiz reports.

## Security considerations

* The token acts with the connector account's permissions. Treat it like a password; store it only in the external system's server-side
  configuration.
* Material downloads accept HTTPS URLs on allowed hosts only. Redirects are followed at most three times and only to
  allowed hosts; Moodle's own blocked hosts and ports apply to every request; files are streamed to disk and stopped
  at the size limit.
* Content is sanitised before storage because Moodle displays page content, activity descriptions and question text
  without further cleaning.
* Material files are ordinary file resources: Moodle displays them exactly as files a teacher uploads, including
  HTML files. Allow only the external system's own material host in **Allowed material hosts**.
* Deleting or replacing is limited to activities and questions the plugin created and never removes student work.
* Student removal is limited to half of a class within 7 days, whatever the number of requests.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Invalid token` / `Access control exception` | The token is wrong, expired, or the user is not in *Authorised users* for the service. |
| `forcepasswordchangenotice` | The the connector account must change its password; clear that flag on the account. |
| `Course or activity not accessible` / `Not enrolled` | The the connector account lacks `moodle/course:view` in that course's category, or is not enrolled. |
| `Sorry, but you do not currently have permissions to do that (…)` | The role is missing the capability shown; see [Required capabilities](#required-capabilities). |
| Lesson pushed but no material files, warning `materialsnotreplaced` | Add the material host to **Allowed material hosts**, and check the URL is HTTPS and smaller than the size limit. If the host is on your internal network, Moodle's *cURL blocked hosts list* blocks it by default; only allow that one internal address, and only if the external system is really hosted there. |
| `The material URL returned a web page instead of a file` | The file does not exist at the source, which answered with an HTML page. |
| `This exam already has N attempt(s)…` | Questions of an attempted exam cannot be replaced; push a new exam. |
| `This sync would remove N students, after R removed in the last 7 days…` | The list looks incomplete, or more than half of the class would leave within 7 days. Send the full class; remove large groups in Moodle. |
| `The request contained N students but M were expected…` | The request was truncated; send at most 1000 students per request. |
| `Student synchronisation requires expectedcount…` | Send `expectedcount` with every request. |
| `Another student synchronisation of this course is still running…` | Retry after the running request finished. |
| `Subsection N was not deleted because it contains activities…` | A teacher added an activity to the lesson, or students worked in it. Remove the subsection in Moodle if it should go. |
| Results have empty `email` / `idnumber` | Adjust *Show user identity* and `moodle/site:viewuseridentity`; see [Grade retrieval](#grade-retrieval). |
| `Can not find data record in database table external_functions` | The plugin upgrade was not completed; visit *Site administration → Notifications*. |

## Known limitations

* Grades are read from the last finished attempt, not from the quiz's grading method or the gradebook, and do not
  reflect gradebook overrides.
* `local_extsync_fetch_grades` is not paginated; very large quizzes are slow to read.
* Material files are downloaded during the web service request.
* After a course backup and restore, restored activities are not recognised as created by the plugin; the external system
  creates new copies on the next push and leaves the restored ones untouched.
* New assignments only enable Moodle's own submission and feedback plugins according to your site defaults.
* One Moodle site connects to one external system through one token per service account.
* The removal limit is based on the class size recorded by the earliest synchronisation of the last 7 days. A class
  that had no manually enrolled students at that synchronisation cannot lose students through the external system until it is
  7 days old. A class enlarged with other accounts and left alone for 7 days has a correspondingly larger limit.
* Replaced exam questions that the plugin cannot prove it imported (for example from versions before 2.0) stay in
  the exam's question bank.

## Privacy

The plugin stores no personal data in Moodle. When the connector account calls `local_extsync_fetch_grades`, the following
data is sent to the external system: Moodle user id; username, ID number and email where permitted; attempt finish
time; attempt grade; per-question results. The plugin declares this in the Moodle Privacy API as an external
location. Data received from the external system for synchronisation (student keys, lesson content) is used only to match users
and create course content. Retention and processing in the external system are governed by your agreement with the external system.

## Support

**Developers** can use the plugin directly: this README documents every function, setting and capability, and
[`integrations/`](integrations/) has a ready-made Python client, a Claude skill and GPT/Gemini tool definitions.
Bugs and feature requests: [GitHub Issues](https://github.com/Zombie-01/ARIGUN-JAMSRAN/issues).

**Schools and organisations without a developer** can get help with installation, web service setup, connecting
an existing system (student information system, content platform, spreadsheets) or setting up AI assistants to
publish lessons and quizzes. Contact **mm6816557@gmail.com** — in English or Mongolian.

**Тусламж хэрэгтэй бол:** суулгах, web service тохируулах, танай системийг Moodle-той холбох, эсвэл AI туслахаар
хичээл, шалгалт оруулах тохиргоо хийлгэхийг хүсвэл **mm6816557@gmail.com** хаягаар монгол эсвэл англи хэлээр
холбогдоорой.

Report security issues privately to the address above rather than in the public issue tracker.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
