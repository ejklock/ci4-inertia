# Fuzz corpus seeds

One file per input, read by `php-fuzzer` as the starting corpus of each target. The `composer fuzz` scripts copy a target's seeds to `build/fuzz/` first, so the fuzzer's own additions never land in the repository.

| Directory | Target | Input is |
| --- | --- | --- |
| `except-patterns/` | `ExceptPatternsTarget.php` | the request URI after its leading `/` |
| `site-origin/` | `SiteOriginTarget.php` | a Referer or previous URL |

## Where the seeds come from

- `site-origin/`: the foreign-referer cases of `tests/CsrfFiltersTest.php` and the data set of `tests/Support/SiteOriginTest.php`: other host, scheme-relative, suffix trick, other port, other scheme, `javascript:`, malformed, userinfo, a backslash hiding the real host, a backslash, tab and DEL in the path, `%5C`, an upper-case host and an explicit default port.
- `except-patterns/`: the cases of `tests/Ssr/HttpGatewayTest.php` for the exclusion check: path and full-URL patterns, `&&&`, `=x`, `%ZZ`, bracket keys, a `;`-separated query, backslash, tab, DEL, an encoded slash, an absolute request URI, and a query with more pairs than the `max_input_vars=50` the scripts set.

A crash the fuzzer finds is written to `build/fuzz/crash/`; copy a minimized input here as a seed once the defect is fixed.
