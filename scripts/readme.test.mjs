import assert from "node:assert/strict";
import { existsSync, readFileSync, readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { test } from "node:test";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

/** The fenced code blocks of a Markdown text, each with its info string and body. */
export function codeBlocks(markdown) {
  const blocks = [];
  const fence = /^[ \t]*(`{3,}|~{3,})[ \t]*([\w+-]*)[^\n]*\n([\s\S]*?)^[ \t]*\1[ \t]*$/gm;
  for (const match of markdown.matchAll(fence)) {
    blocks.push({ lang: match[2].toLowerCase(), body: match[3] });
  }
  return blocks;
}

const isRootView = ({ body }) => /<html|<body|inertia_app\(/i.test(body);

const SCRIPT_TAG = /<script\b[^>]*\btype="application\/json"[^>]*>([\s\S]*?)<\/script>/g;

/** Whether the text from `start` to `end` lies inside the body of a JSON script tag of `body`. */
const insideJsonScript = (body, start, end) =>
  [...body.matchAll(SCRIPT_TAG)].some((tag) => {
    const from = tag.index + tag[0].indexOf(">") + 1;
    return start >= from && end <= from + tag[1].length;
  });

/** The reasons the root views in a README would print the page unsafely, or that there is no root view to judge. */
export function rootViewProblems(markdown) {
  const problems = [];
  const blocks = codeBlocks(markdown);

  for (const block of blocks) {
    if (/data-page="<\?=/.test(block.body) || /esc\(\s*\$page/.test(block.body)) {
      problems.push("a block prints the page through esc() into a data-page attribute");
    }
    for (const call of block.body.matchAll(/json_encode\(\s*\$page\b[^)]*\)/g)) {
      if (!call[0].includes("JSON_HEX_TAG")) {
        problems.push("a block encodes the page with json_encode() and no JSON_HEX_TAG");
      } else if (!insideJsonScript(block.body, call.index, call.index + call[0].length)) {
        problems.push('a block encodes the page outside a <script type="application/json"> tag');
      }
    }
  }

  const rootViews = blocks.filter(isRootView);
  if (rootViews.length === 0) {
    problems.push("the README has no root view block");
  }
  for (const view of rootViews) {
    const usesHelper = /inertia_app\(\s*\$page\b/.test(view.body);
    const safeManual = [...view.body.matchAll(/json_encode\(\s*\$page\b[^)]*JSON_HEX_TAG[^)]*\)/g)].some((call) =>
      insideJsonScript(view.body, call.index, call.index + call[0].length),
    );
    if (!usesHelper && !safeManual) {
      problems.push("a root view block does not print the page through inertia_app($page)");
    }
  }
  return problems;
}

/** Whether some root view block calls `inertia_app($page`. */
export function hasInertiaAppView(markdown) {
  return codeBlocks(markdown).some((block) => isRootView(block) && /inertia_app\(\s*\$page\b/.test(block.body));
}

/** The `@inertiajs/*` installs that pin a 2.x client. */
export function oldClientPins(markdown) {
  return [...markdown.matchAll(/@inertiajs\/[\w-]+(?:@|"\s*:\s*")[\^~v]?2(?![\d])[\w.^~*-]*"?/g)].map((match) => match[0]);
}

/** The phrases that describe the 0.x package. */
export function staleStatements(markdown) {
  return [/protocol v2/i, /not supported by this package yet/i, /Inertia protocol \| v2/i].filter((pattern) => pattern.test(markdown)).map(String);
}

/** The package classes the README imports that have no file under src. */
export function unknownClasses(markdown, srcDir) {
  const missing = [];
  for (const match of markdown.matchAll(/^\s*use Ejklock\\Ci4Inertia\\([\w\\]+)(?: as \w+)?;/gm)) {
    if (!existsSync(join(srcDir, `${match[1].replaceAll("\\", "/")}.php`))) {
      missing.push(match[1]);
    }
  }
  return missing;
}

const OLD_ROOT_VIEW = [
  "```php",
  "<!DOCTYPE html>",
  "<html><body>",
  "<div id=\"app\" data-page=\"<?= esc($page, 'attr') ?>\"></div>",
  "</body></html>",
  "```",
].join("\n");

test("a root view that prints the page through esc() into data-page fails", () => {
  const problems = rootViewProblems(OLD_ROOT_VIEW);
  assert.ok(problems.some((problem) => problem.includes("esc()")), problems.join("; "));
});

test("a root view that prints the page with a bare json_encode fails", () => {
  const view = ["```php", "<html><body>", '<script type="application/json"><?= json_encode($page) ?></script>', "</body></html>", "```"].join("\n");
  const problems = rootViewProblems(view);
  assert.ok(problems.some((problem) => problem.includes("JSON_HEX_TAG")), problems.join("; "));
});

test("json_encode with JSON_HEX_TAG inside a JSON script tag passes", () => {
  const view = [
    "```php",
    "<html><body>",
    '<script data-page="app" type="application/json"><?= json_encode($page, JSON_HEX_TAG) ?></script>',
    '<div id="app"></div>',
    "</body></html>",
    "```",
  ].join("\n");
  assert.deepEqual(rootViewProblems(view), []);
});

test("json_encode with JSON_HEX_TAG outside a JSON script tag fails", () => {
  const view = ["```php", "<html><body>", "<div data-x='<?= json_encode($page, JSON_HEX_TAG) ?>'></div>", "</body></html>", "```"].join("\n");
  const problems = rootViewProblems(view);
  assert.ok(problems.some((problem) => problem.includes("outside")), problems.join("; "));
});

test("a root view that prints the page through inertia_app passes", () => {
  const view = ["```php", "<html><head><?= inertia_head($page) ?></head>", "<body><?= inertia_app($page) ?></body></html>", "```"].join("\n");
  assert.deepEqual(rootViewProblems(view), []);
  assert.equal(hasInertiaAppView(view), true);
});

test("a README with no root view block fails, so the check cannot pass by finding nothing", () => {
  const readme = ["# Title", "", "```bash", "composer require x/y", "```", "", "```php", "echo 'hello';", "```"].join("\n");
  const problems = rootViewProblems(readme);
  assert.ok(problems.some((problem) => problem.includes("no root view")), problems.join("; "));
  assert.equal(hasInertiaAppView(readme), false);
});

test("a root view that prints something else than the page through the helper fails", () => {
  const view = ["```html", "<html><body><div id=\"app\"></div></body></html>", "```"].join("\n");
  assert.ok(rootViewProblems(view).some((problem) => problem.includes("inertia_app")));
});

test("an @inertiajs install pinned to the 2.x range fails", () => {
  assert.deepEqual(oldClientPins("npm install @inertiajs/vue3@^2 vue"), ["@inertiajs/vue3@^2"]);
  assert.deepEqual(oldClientPins("npm install @inertiajs/react@2.3.1"), ["@inertiajs/react@2.3.1"]);
});

test("an @inertiajs install of the latest or a 3.x range passes", () => {
  assert.deepEqual(oldClientPins("npm install @inertiajs/vue3 vue"), []);
  assert.deepEqual(oldClientPins("npm install @inertiajs/vue3@^3 @inertiajs/svelte@3.1.0 @inertiajs/react@^20"), []);
});

test("the 0.x protocol statements are reported", () => {
  assert.equal(staleStatements("This package supports Inertia protocol v2.").length, 1);
  assert.equal(staleStatements("it is not supported by this package yet.").length, 1);
  assert.deepEqual(staleStatements("This package speaks the Inertia v3 protocol."), []);
});

test("a use statement for a class that does not exist is reported", () => {
  const src = join(root, "src");
  assert.deepEqual(unknownClasses("use Ejklock\\Ci4Inertia\\LazyProp;\nuse Ejklock\\Ci4Inertia\\OptionalProp;", src), ["LazyProp"]);
});

const SAFE_VIEW = ["```php", "<html><head><?= inertia_head($page) ?></head>", "<body><?= inertia_app($page) ?></body></html>", "```"].join("\n");
const UNSAFE_VIEW_LINES = ["<!DOCTYPE html>", "<html><body>", "<div id=\"app\" data-page=\"<?= esc($page, 'attr') ?>\"></div>", "</body></html>"];

for (const [name, open, close] of [
  ["an unlabeled block", "```", "```"],
  ["an html+php block", "```html+php", "```"],
  ["a phtml block", "```phtml", "```"],
  ["a ~~~php fence", "~~~php", "~~~"],
  ["an indented tilde fence with no language", "  ~~~", "  ~~~"],
]) {
  test(`an esc() root view in ${name} fails even next to a safe view`, () => {
    const readme = [SAFE_VIEW, "", open, ...UNSAFE_VIEW_LINES, close].join("\n");
    const problems = rootViewProblems(readme);
    assert.ok(problems.some((problem) => problem.includes("esc()")), problems.join("; "));
  });
}

test("a bare json_encode root view in a ~~~ fence fails", () => {
  const readme = [SAFE_VIEW, "", "~~~", "<html><body>", '<script type="application/json"><?= json_encode($page) ?></script>', "</body></html>", "~~~"].join("\n");
  assert.ok(rootViewProblems(readme).some((problem) => problem.includes("JSON_HEX_TAG")));
});

test("a root view in an unlabeled block that skips inertia_app fails", () => {
  const readme = [SAFE_VIEW, "", "```", '<html><body><div id="app"></div></body></html>', "```"].join("\n");
  assert.ok(rootViewProblems(readme).some((problem) => problem.includes("inertia_app")));
});

test("a bash block and a safe view in a ~~~ fence pass", () => {
  const readme = ["```bash", "composer require x/y", "```", "", "~~~php", "<html><body><?= inertia_app($page) ?></body></html>", "~~~"].join("\n");
  assert.deepEqual(rootViewProblems(readme), []);
});

test("JSON_HEX_TAG output in a single-quoted attribute fails when the block has a JSON script tag elsewhere", () => {
  const view = [
    "```php",
    "<html><body>",
    '<script type="application/json"><?= json_encode($other, JSON_HEX_TAG) ?></script>',
    "<div data-x='<?= json_encode($page, JSON_HEX_TAG) ?>'></div>",
    "</body></html>",
    "```",
  ].join("\n");
  const problems = rootViewProblems(view);
  assert.ok(problems.some((problem) => problem.includes("outside")), problems.join("; "));
});

test("an @inertiajs pin written in package.json form fails", () => {
  assert.deepEqual(oldClientPins('"@inertiajs/vue3": "^2.0.0"'), ['@inertiajs/vue3": "^2.0.0"']);
  assert.equal(oldClientPins('"@inertiajs/react": "2.3.1"').length, 1);
});

test("an @inertiajs pin in package.json form to 3.x passes", () => {
  assert.deepEqual(oldClientPins('"@inertiajs/vue3": "^3.0.0", "@inertiajs/react": "^20.1.0"'), []);
});

const readme = readFileSync(join(root, "README.md"), "utf8");

test("README: every root view prints the page safely and one uses inertia_app", () => {
  assert.deepEqual(rootViewProblems(readme), []);
  assert.equal(hasInertiaAppView(readme), true);
});

test("README: no install pins the 2.x client and no 0.x statement is left", () => {
  assert.deepEqual(oldClientPins(readme), []);
  assert.deepEqual(staleStatements(readme), []);
});

test("README: every package class it imports exists", () => {
  assert.deepEqual(unknownClasses(readme, join(root, "src")), []);
});

const read = (path) => readFileSync(join(root, path), "utf8");
const publicMethods = (path) => [...read(path).matchAll(/^    public (?:static )?function (\w+)\(/gm)].map((match) => match[1]).filter((name) => !name.startsWith("__"));
const mentions = (name) => new RegExp(`\`[^\`]*\\b${name.replace("$", "\\$")}\\b`).test(readme);

test("README: names every public method of ResponseFactory", () => {
  const missing = publicMethods("src/ResponseFactory.php").filter((name) => !mentions(name));
  assert.deepEqual(missing, []);
});

test("README: names every public property of the Inertia config", () => {
  const properties = [...read("src/Config/Inertia.php").matchAll(/^    public (?:\??[\w|]+) \$(\w+)/gm)].map((match) => match[1]);
  assert.ok(properties.length > 15);
  assert.deepEqual(properties.filter((name) => !mentions(`$${name}`) && !mentions(name)), []);
});

test("README: names every filter, spark command and helper", () => {
  const filters = readdirSync(join(root, "src/Filters")).map((file) => file.replace(".php", ""));
  const commands = [...["StartSsr", "StopSsr", "CheckSsr"].flatMap((file) => [...read(`src/Commands/${file}.php`).matchAll(/\$name\s+= '([^']+)'/g)].map((match) => match[1]))];
  const helpers = [...read("src/Helpers/inertia_helper.php").matchAll(/function (\w+)\(/g)].map((match) => match[1]);
  assert.equal(filters.length, 5);
  assert.equal(commands.length, 3);
  assert.equal(helpers.length, 4);
  assert.deepEqual([...filters, ...commands, ...helpers].filter((name) => !readme.includes(name)), []);
});

test("README: names the prop, exception and testing classes", () => {
  const names = [
    "AlwaysProp",
    "DeferProp",
    "MergeProp",
    "OnceProp",
    "OptionalProp",
    "ScrollProp",
    "ScrollMetadata",
    "ProvidesInertiaProperties",
    "ProvidesInertiaProperty",
    "InertiaExceptionHandler",
    "handleExceptionsUsing",
    "ExceptionResponse",
    "InertiaTestTrait",
    "AssertableInertia",
    "SsrRenderFailed",
  ];
  assert.deepEqual(names.filter((name) => !readme.includes(name)), []);
  const testing = [...read("src/Testing/AssertableInertia.php").matchAll(/^    public function (\w+)\(/gm)].map((match) => match[1]).filter((name) => !name.startsWith("__"));
  assert.deepEqual(testing.filter((name) => !mentions(name)), []);
});

test("README: carries a Security section with the nine warnings", () => {
  const section = readme.split(/^## Security\s*$/m)[1]?.split(/^## /m)[0] ?? "";
  for (const needle of [
    "inertia_app($page)",
    "JSON_HEX_TAG",
    "location(",
    "allowedHostnames",
    "partial-reload",
    "13714",
    "public/hot",
    "ssrHotUrl",
    "max_input_vars",
    "getMessage()",
    "Cache-Control",
    "Cookie::$secure",
    "$globals['before']",
  ]) {
    assert.ok(section.includes(needle), `the Security section lacks ${needle}`);
  }
});

test("README: links to the changelog and the upgrade guide instead of repeating them", () => {
  assert.ok(readme.includes("](CHANGELOG.md)"));
  assert.ok(readme.includes("](UPGRADING.md)"));
  assert.ok(readme.includes("](SECURITY.md)"));
});
