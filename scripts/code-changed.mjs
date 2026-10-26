import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const DOC_PATHS = [
  /^docs\//,
  /^\.github\/ISSUE_TEMPLATE\//,
  /^\.github\/PULL_REQUEST_TEMPLATE\.md$/,
  /^(README|CHANGELOG|CONTRIBUTING|CODE_OF_CONDUCT|SECURITY)\.md$/,
];

export function isDocsOnlyPath(path) {
  return DOC_PATHS.some((pattern) => pattern.test(path));
}

export function touchesCode(paths) {
  return paths.length === 0 || paths.some((path) => !isDocsOnlyPath(path));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const paths = readFileSync(0, "utf8").split("\n").filter(Boolean);
  process.stdout.write(String(touchesCode(paths)));
}
