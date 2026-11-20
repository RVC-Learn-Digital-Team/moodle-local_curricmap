"""Exercise the built AMD picker using synthetic PHP-rendered fixtures and Selenium.

Create test_results/content_picker_preview before running the PHPUnit suite to opt
into fixture export. Copy those three fixture files from the isolated test copy to
that directory, then run this script. The existing playground Selenium container
is used for a browser session only; no request reaches the live Moodle site.
"""
import base64
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "test_results" / "content_picker_preview"


def request(method, path, data=None):
    """Use the existing test browser's local WebDriver endpoint without installing tools."""
    command = ["docker", "exec", "-i", "moodle-docker-selenium-1", "curl", "--fail-with-body",
               "--silent", "--show-error", "-X", method, "-H", "Content-Type: application/json"]
    if data is not None:
        command += ["--data-binary", "@-"]
    command += ["http://localhost:4444" + path]
    result = subprocess.run(command, input=json.dumps(data) if data is not None else None,
                            text=True, capture_output=True, check=False)
    if result.returncode:
        raise RuntimeError(result.stdout or result.stderr)
    value = json.loads(result.stdout)["value"]
    if isinstance(value, dict) and "error" in value:
        raise RuntimeError(value["message"])
    return value


print(f"Browser test evidence: {OUTPUT}", flush=True)
editor = (OUTPUT / "editor.html").read_text()
search = (OUTPUT / "search.html").read_text()
strings = json.loads((OUTPUT / "strings.json").read_text())
css = (ROOT / "styles.css").read_text()
built = (ROOT / "amd/build/content_picker.min.js").read_text()
fixture = """<!doctype html><meta charset='utf-8'><title>Content mapping review</title><style>
body {font:16px/1.5 Arial,sans-serif;color:#212529;margin:28px;background:white}
button,input {font:inherit} button {cursor:pointer;border-radius:4px;padding:6px 12px;background:white}
button:disabled {cursor:default;opacity:.65} .btn-primary {background:#65419a;color:white;border:1px solid #65419a}
.btn-outline-primary {color:#65419a;border:1px solid #65419a}.btn-secondary {background:#e9ecef;border:1px solid #aaa}
.btn-sm {font-size:14px}.btn-link {border:0;color:#65419a}.form-control {box-sizing:border-box;width:100%;padding:8px}
.small,small {font-size:14px}.my-2 {margin:8px 0}.mt-2 {margin-top:8px}.mt-1 {margin-top:4px}
.text-danger {color:#a12328} [hidden] {display:none} summary {cursor:pointer;font-weight:bold}
</style>""" + "<style>" + css + "</style><h1>Effective Note Making</h1>" + (
    "<p>Effective note-making is a key academic skill that helps you organise information and support learning.</p>"
    "<div data-curricmap-tree data-courseid='10'><details data-curricmap-branch='s1' open><summary>"
    "Section <span data-curricmap-counts='1' data-mapped='0' data-count-template='__MAPPED__ activities mapped'>"
    "0 activities mapped</span></summary>" + editor.replace('s1', 'c1') + "</details><hr><section>" +
    editor.replace('s1', 's2') + "</section></div>")

session = request("POST", "/session", {"capabilities": {"alwaysMatch": {
    "browserName": "chrome", "goog:chromeOptions": {"args": ["--headless=new", "--no-sandbox"]}}}})["sessionId"]
base = "/session/" + session
try:
    request("POST", base + "/window/rect", {"width": 1440, "height": 1300})
    request("POST", base + "/url", {"url": "data:text/html;base64," + base64.b64encode(fixture.encode()).decode()})
    setup = """
        window.calls = []; window.failSave = false; window.searches = [];
        const search = arguments[0], strings = arguments[1];
        window.modules = {
            'core/ajax': {call: ([call]) => { window.calls.push(call); return [new Promise((resolve, reject) => {
                setTimeout(() => window.failSave ? reject(new Error('Simulated failure')) : resolve({mappings:
                    call.args.nodeuuids.map(uuid => ({uuid, label: document.querySelector(
                        '[data-curricmap-pick="' + uuid + '"]').dataset.curricmapPicklabel}))}), 30);
            })]; }},
            'core/fragment': {loadFragment: (component, callback, context, params) => {
                window.searches.push(params);
                const html = params.query === 'missing' ? '<p>No matching curriculum items.</p>' : search;
                return {then: (done) => new Promise(resolve => setTimeout(() => resolve(done(html, '')),
                    params.query === 'slow' ? 400 : 30))};
            }},
            'core/templates': {replaceNodeContents: async(node, html) => { node.innerHTML = html; }},
            'core/str': {getStrings: async(requests) => requests.map(({key}) => strings[key])}
        };
        window.picker = {};
        window.define = (name, dependencies, factory) => factory(...dependencies.map(
            dependency => dependency === 'exports' ? window.picker : window.modules[dependency]));
    """
    request("POST", base + "/execute/sync", {"script": setup, "args": [search, strings]})
    request("POST", base + "/execute/sync", {"script": built, "args": []})
    result = request("POST", base + "/execute/async", {"script": """
        const done = arguments[arguments.length - 1];
        (async() => {
            const checks = [];
            const check = (ok, message) => { if (!ok) throw new Error(message); checks.push(message); };
            const tick = (ms = 80) => new Promise(resolve => setTimeout(resolve, ms));
            await window.picker.init(document.querySelector('[data-curricmap-tree]'), 10);
            const [one, two] = document.querySelectorAll('[data-curricmap-editor]');
            const find = (row, name) => row.querySelector('[data-curricmap-' + name + ']');
            const picks = one.querySelectorAll('[data-curricmap-pick]');
            const count = row => row.querySelectorAll('.curricmap-choice').length;
            picks[0].click(); picks[1].click();
            check(count(one) === 2, 'Session and outcome can be picked independently');
            find(two, 'pick').click();
            find(one, 'save').click();
            check(find(one, 'pick').disabled, 'Saving disables duplicate changes');
            await tick();
            check(window.calls[0].args.nodeuuids.length === 2, 'Save sends the complete selection for one row');
            check(document.querySelector('[data-curricmap-counts]').dataset.mapped === '1',
                'Successful activity save updates the parent summary count');
            check(find(one, 'save').disabled && !find(two, 'save').disabled, 'Other rows retain their unsaved choices');
            find(two, 'cancel').click();
            check(count(two) === 0 && find(two, 'save').disabled, 'Cancel restores an empty saved selection');
            find(one, 'remove').click(); find(one, 'cancel').click();
            check(count(one) === 2, 'Cancel restores saved mappings after removal');
            find(one, 'remove').click(); window.failSave = true; find(one, 'save').click(); await tick();
            check(count(one) === 1 && !find(one, 'save').disabled && find(one, 'save-status').textContent.includes(
                'Simulated failure'), 'Save failure preserves the draft and offers retry');
            window.failSave = false; find(one, 'save').click(); await tick();
            check(find(one, 'save').disabled, 'A failed save can be retried successfully');
            find(one, 'remove').click(); find(one, 'save').click(); await tick();
            check(window.calls.at(-1).args.nodeuuids.length === 0, 'Saving an empty selection removes all item mappings');
            check(document.querySelector('[data-curricmap-counts]').dataset.mapped === '0',
                'Clearing activity mappings updates the parent summary count');
            find(one, 'open-browser').click(); await tick();
            check(!find(one, 'browser').hidden && document.activeElement === find(one, 'query'),
                'More curriculum opens a prominent panel with search focused');
            const input = find(one, 'query');
            input.value = 'slow'; input.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', bubbles:true}));
            input.value = 'missing'; input.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', bubbles:true}));
            await tick(500);
            check(find(one, 'results').textContent.includes('No matching curriculum'), 'Stale search responses are ignored');
            input.value = 'strategies'; input.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', bubbles:true}));
            await tick();
            find(one, 'results').querySelector('button').click();
            check(count(one) === 1, 'Search results add to the same draft selection');
            picks[0].click();
            window.scrollTo(0, 0);
            return checks;
        })().then(result => done({checks:result}), error => done({error:error.message}));
        """, "args": []})
    if "error" in result:
        raise AssertionError(result["error"])
    screenshot = request("GET", base + "/screenshot")
    (OUTPUT / "desktop.png").write_bytes(base64.b64decode(screenshot))
    request("POST", base + "/window/rect", {"width": 480, "height": 1100})
    request("POST", base + "/execute/sync", {"script": "window.scrollTo(0, 0)", "args": []})
    screenshot = request("GET", base + "/screenshot")
    (OUTPUT / "mobile.png").write_bytes(base64.b64decode(screenshot))
    overflow = request("POST", base + "/execute/sync", {"script":
        "return document.documentElement.scrollWidth > window.innerWidth", "args": []})
    if overflow:
        raise AssertionError("Mobile layout overflows horizontally")
    result["checks"].append("Mobile layout has no horizontal overflow")
    (OUTPUT / "results.json").write_text(json.dumps(result, indent=2) + "\n")
    print(f"Passed {len(result['checks'])} browser checks. Screenshots and results: {OUTPUT}")
finally:
    request("DELETE", base)
