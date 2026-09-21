#!/usr/bin/env python3
"""Call the External Sync (local_extsync) Moodle web service, and describe it as AI tools.

Standard library only. Moodle's REST server takes form-encoded parameters, not JSON, and nested
lists must be spelled pages[0][key]=...; this file does that encoding so callers (people, scripts,
Claude, GPT, Gemini) can work in plain JSON.

    set EXTSYNC_URL=https://moodle.example.edu    (site root, no trailing path)
    set EXTSYNC_TOKEN=...                          (token for the "External Sync push" service)

    python extsync.py push_exam params.json        call a function, print the JSON result
    python extsync.py push_exam -                  same, parameters from stdin
    python extsync.py --tools claude|openai|gemini print the tool definitions
    python extsync.py --selftest                   check the encoding, no network
"""

import json
import os
import sys
import urllib.parse
import urllib.request


def _obj(props, required):
    return {"type": "object", "properties": props, "required": required}


def _int(desc):
    return {"type": "integer", "description": desc}


def _str(desc):
    return {"type": "string", "description": desc}


def _bool(desc):
    return {"type": "boolean", "description": desc}


def _list(items, desc):
    return {"type": "array", "items": items, "description": desc}


# One definition per web service function; the three AI formats below are derived from it.
TOOLS = [
    {
        "name": "push_lesson",
        "description": (
            "Create or update a lesson in a Moodle course: a section with an assignment, pages, "
            "downloaded material files and quizzes. Pass the ids returned by the previous push "
            "(sectionid, assigncmid, cmid of pages/quizzes) to update in place instead of creating again."
        ),
        "parameters": _obj({
            "courseid": _int("Target course id"),
            "name": _str("Lesson topic, used as the section name"),
            "sectionid": _int("Existing section id from a previous push, 0 to create"),
            "unitname": _str("Unit name; the lesson then goes into a subsection of that unit's section"),
            "unitsectionid": _int("Existing unit section id, 0 to create"),
            "subsectioncmid": _int("Existing subsection cmid, 0 to create"),
            "renamesection": _bool("Overwrite the section name and summary; false when reusing a teacher's section"),
            "summary": _str("Section summary, HTML"),
            "makeassign": _bool("Create/update the assignment; false for a lesson without one"),
            "assigncmid": _int("Existing assignment cmid, 0 to create"),
            "assignname": _str("Assignment name; empty uses the lesson topic"),
            "assignintro": _str("Assignment description, HTML"),
            "files": _list(_obj({
                "name": _str("File name"),
                "url": _str("HTTPS URL on a host listed in the plugin's Allowed material hosts"),
                "description": _str("Activity name"),
            }, ["name", "url"]), "Lesson material files"),
            "filecmids": _list(_int("cmid"), "File activity cmids from the previous push"),
            "pages": _list(_obj({
                "key": _str("Stable key (letters, digits, - _), matches the page across pushes"),
                "name": _str("Page name"),
                "html": _str("Page body, HTML"),
                "cmid": _int("Existing cmid, 0 to create"),
            }, ["key", "name", "html"]), "Lesson pages in display order"),
            "quizcmid": _int("Existing quiz cmid, 0 to create"),
            "quizname": _str("Quiz name"),
            "quizxml": _str("Quiz questions in Moodle XML; empty for no quiz"),
            "quizzes": _list(_obj({
                "key": _str("Stable key, matches the quiz across pushes"),
                "name": _str("Quiz name"),
                "xml": _str("Questions in Moodle XML"),
                "cmid": _int("Existing cmid, 0 to create"),
            }, ["key", "name", "xml"]), "Extra quizzes, e.g. one per difficulty level"),
            "ordering": _list(_str("Order token such as assign, files, page:<key>, quiz:<key>"),
                              "Module order inside the section"),
            "deletecmids": _list(_int("cmid"), "Modules to remove; only modules this plugin created are deleted"),
        }, ["courseid", "name"]),
    },
    {
        "name": "push_exam",
        "description": (
            "Create a quiz from Moodle XML questions, or replace the questions of an existing quiz "
            "(refused once students have attempted it)."
        ),
        "parameters": _obj({
            "courseid": _int("Target course id"),
            "name": _str("Quiz name"),
            "xml": _str("Questions in Moodle XML"),
            "sectionid": _int("Existing section id, 0 to create one"),
            "sectionname": _str("Name of the section when one is created"),
            "quizcmid": _int("Existing quiz cmid, 0 to create"),
            "intro": _str("Quiz description, HTML"),
            "replace": _bool("Replace the questions of an existing quiz"),
            "timeopen": _int("Unix time the quiz opens, 0 for none"),
            "timeclose": _int("Unix time the quiz closes, 0 for none"),
            "timelimit": _int("Time limit in seconds, 0 for none"),
            "attempts": _int("Allowed attempts, 0 for unlimited"),
        }, ["courseid", "name", "xml"]),
    },
    {
        "name": "sync_students",
        "description": (
            "Enrol a list of existing Moodle users as students of a course. With removemissing, "
            "manually enrolled students not in the list are unenrolled, at most half the class per week."
        ),
        "parameters": _obj({
            "courseid": _int("Target course id"),
            "students": _list(_obj({
                "idnumber": _str("Student ID number"),
                "username": _str("Moodle username"),
                "email": _str("Email"),
            }, []), "Students; each needs at least one of idnumber, username, email (at most 1000)"),
            "expectedcount": _int("Number of students sent, to detect a truncated request"),
            "removemissing": _bool("Unenrol manually enrolled students missing from the list"),
        }, ["courseid", "students", "expectedcount"]),
    },
    {
        "name": "fetch_grades",
        "description": "Read the students' attempts and per-question marks of a quiz.",
        "parameters": _obj({
            "courseid": _int("Course id"),
            "quizcmid": _int("Quiz cmid"),
        }, ["courseid", "quizcmid"]),
    },
]


def claude_tools():
    """Tool definitions for the Anthropic Messages API (tools=[...])."""
    return [{"name": t["name"], "description": t["description"], "input_schema": t["parameters"]} for t in TOOLS]


def openai_tools():
    """Tool definitions for OpenAI function calling (tools=[...])."""
    return [{"type": "function", "function": t} for t in TOOLS]


def gemini_tools():
    """Function declarations for the Gemini API (tools=[{"function_declarations": [...]}])."""
    return [{"function_declarations": TOOLS}]


def flatten(value, prefix=""):
    """Encode a JSON value the way Moodle's REST server expects: a[0][b]=..., booleans as 1/0."""
    if isinstance(value, dict):
        pairs = []
        for k, v in value.items():
            pairs += flatten(v, f"{prefix}[{k}]" if prefix else k)
        return pairs
    if isinstance(value, list):
        pairs = []
        for i, v in enumerate(value):
            pairs += flatten(v, f"{prefix}[{i}]")
        return pairs
    if isinstance(value, bool):
        return [(prefix, "1" if value else "0")]
    return [(prefix, "" if value is None else str(value))]


def call(function, params, url=None, token=None, timeout=300):
    """Call local_extsync_<function> and return the decoded result; raise RuntimeError on a Moodle error."""
    url = (url or os.environ["EXTSYNC_URL"]).rstrip("/") + "/webservice/rest/server.php"
    token = token or os.environ["EXTSYNC_TOKEN"]
    if not function.startswith("local_extsync_"):
        function = "local_extsync_" + function
    body = [("wstoken", token), ("wsfunction", function), ("moodlewsrestformat", "json")] + flatten(params)
    request = urllib.request.Request(url, data=urllib.parse.urlencode(body).encode())
    with urllib.request.urlopen(request, timeout=timeout) as response:
        result = json.loads(response.read().decode())
    if isinstance(result, dict) and "exception" in result:
        raise RuntimeError(f"{result.get('errorcode')}: {result.get('message')}")
    return result


def selftest():
    assert flatten({"a": 1, "b": True, "c": False}) == [("a", "1"), ("b", "1"), ("c", "0")]
    assert flatten({"pages": [{"key": "k", "cmid": 0}]}) == [("pages[0][key]", "k"), ("pages[0][cmid]", "0")]
    assert flatten({"ids": [5, 7]}) == [("ids[0]", "5"), ("ids[1]", "7")]
    assert flatten({"files": []}) == []
    assert [t["name"] for t in claude_tools()] == ["push_lesson", "push_exam", "sync_students", "fetch_grades"]
    for tool in TOOLS:
        props = tool["parameters"]["properties"]
        assert set(tool["parameters"]["required"]) <= set(props), tool["name"]
    print("selftest ok")


def main(argv):
    if argv[:1] == ["--selftest"]:
        selftest()
    elif argv[:1] == ["--tools"] and len(argv) == 2:
        print(json.dumps({"claude": claude_tools, "openai": openai_tools, "gemini": gemini_tools}[argv[1]](),
                         indent=2, ensure_ascii=False))
    elif len(argv) == 2:
        source = sys.stdin if argv[1] == "-" else open(argv[1], encoding="utf-8")
        with source:
            params = json.load(source)
        print(json.dumps(call(argv[0], params), indent=2, ensure_ascii=False))
    else:
        print(__doc__)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
