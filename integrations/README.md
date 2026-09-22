# Using External Sync from scripts and AI assistants

Not part of the Moodle plugin and not included in its ZIP. Everything here drives a Moodle site that already has
`local_extsync` installed, through its web service.

`extsync/extsync.py` is one file, Python 3.8+ standard library only. It calls the four web service functions and
describes them as tool definitions for Claude, OpenAI (GPT) and Gemini.

## 1. What the Moodle administrator does once

Follow *Setup* and *Required capabilities* in the plugin's [README](../README.md): enable REST web services,
create an integration user with the listed capabilities in the courses it should manage, and create a token for
it on the **External Sync push** service. Then hand over the site URL and the token.

## 2. From a script or the command line

```
set EXTSYNC_URL=https://moodle.example.edu
set EXTSYNC_TOKEN=YOUR_TOKEN
python extsync/extsync.py push_exam exam.json
python extsync/extsync.py fetch_grades grades.json
```

The parameters are the JSON objects described by `python extsync/extsync.py --tools claude`.
In Python: `from extsync import call; call("push_exam", {...})`.

## 3. Claude

* **Claude Code** — copy the `extsync` folder to `~/.claude/skills/moodle-extsync/` (or the project's
  `.claude/skills/`), set the two environment variables, and ask for what you want in plain language.
* **Claude apps** — upload the `extsync` folder as a skill (zip it with `SKILL.md` at the top). The skill runs
  `extsync.py`, so the code environment must be able to reach your Moodle site over the network.
* **Claude API** — pass `claude_tools()` as `tools`; for each `tool_use` block, run `call(block.name, block.input)`
  and send the result back as a `tool_result`.

## 4. GPT (OpenAI API) and Gemini

The same pattern: give the model the tool definitions, and execute the calls it asks for with `call()`.

* OpenAI: `tools=openai_tools()`; for each tool call, `call(name, json.loads(arguments))`.
* Gemini: `tools=gemini_tools()`; for each function call, `call(name, dict(args))`.

`python extsync/extsync.py --tools openai` (or `gemini`) prints the definitions as JSON for tools that take
them pasted in.

A ChatGPT *custom GPT* cannot call Moodle directly: GPT Actions need an HTTPS API described in OpenAPI, and
Moodle's REST server takes form-encoded nested parameters that OpenAPI cannot describe well. That would need a
small proxy server, which this folder deliberately does not include.

## 5. Safety

The token acts as its user: anything the model does, that user does. Give the integration user access only to
the courses it should change. The plugin itself refuses to modify or delete anything it did not create for the
same integration user, caps student removals at half a class per week, and will not replace the questions of an exam
students have attempted — but review deletions (`deletecmids`) and `removemissing` before letting a model send them.
