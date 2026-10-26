import assert from "node:assert/strict";
import { test } from "node:test";
import { isDocsOnlyPath, touchesCode } from "./code-changed.mjs";

test("community files, the docs directory and the issue and pull request templates count as docs", () => {
  for (const path of [
    "README.md",
    "CHANGELOG.md",
    "CONTRIBUTING.md",
    "CODE_OF_CONDUCT.md",
    "SECURITY.md",
    "docs/x.md",
    ".github/ISSUE_TEMPLATE/bug.md",
    ".github/PULL_REQUEST_TEMPLATE.md",
  ]) {
    assert.equal(isDocsOnlyPath(path), true, path);
  }
});

test("source, tests, workflows, actions and tool configuration count as code", () => {
  for (const path of [
    "src/InertiaResponse.php",
    "tests/ResponseTest.php",
    ".github/workflows/ci.yml",
    ".github/actions/install-deps/action.yml",
    "composer.json",
    "phpunit.dist.xml",
    "phpstan.neon.dist",
    "infection.json5",
  ]) {
    assert.equal(isDocsOnlyPath(path), false, path);
  }
});

test("Markdown under src or tests counts as code, even when it is named like a docs file", () => {
  for (const path of ["src/prompts/inbound.md", "tests/fixtures/page.md", "src/README.md", "tests/CHANGELOG.md"]) {
    assert.equal(isDocsOnlyPath(path), false, path);
  }
});

test("a change set of only docs skips the code jobs", () => {
  assert.equal(touchesCode(["README.md", "CHANGELOG.md", "docs/x.md", ".github/ISSUE_TEMPLATE/bug.md"]), false);
});

test("one code path among docs runs the code jobs", () => {
  assert.equal(touchesCode(["README.md", "CHANGELOG.md", "docs/x.md", ".github/ISSUE_TEMPLATE/bug.md", "src/InertiaResponse.php"]), true);
  assert.equal(touchesCode([".github/workflows/ci.yml"]), true);
  assert.equal(touchesCode(["composer.json"]), true);
});

test("an empty change set runs the code jobs", () => {
  assert.equal(touchesCode([]), true);
});

test("a path unknown to the rule runs the code jobs", () => {
  assert.equal(touchesCode(["something/else.bin"]), true);
});
