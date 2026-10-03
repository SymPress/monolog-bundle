# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.1.3 — 2026-10-03

- Remove the redundant profiler gate check from the handler override. Native handling evaluates the gate once before its processors while preserving level and bubbling behavior.
- Recheck authorization after a native Logger processor preflight; an earlier open gate cannot authorize a record after a processor closes it.

## 1.1.2 — 2026-10-03

- Exclude development tests, documentation and local QA configuration from WordPress artifacts so the mandatory archive check reviews shipped runtime files.

## 1.1.1 — 2026-10-03

- Preserve diagnostic keys such as `bypass` and `tests_passed` without treating their values as credentials across log messages and exception traces.
- Match `pass` as a key component while retaining password, passphrase, token and WordPress cookie redaction.

## 1.1.0 — 2026-10-03

- Record bounded WordPress security events in a dedicated private rotating audit log, including successful and failed logins, role changes, activations and relevant option changes.
- Mask custom WordPress/PHP session cookies and alternate API/private/access-key context names.

## 1.0.3 — 2026-10-02

- Mask credential and SQL literal substrings while retaining operation diagnostics.
- Preserve exception locations, bounded argument-free stack frames and chained exceptions.
- Mask known credentials and sensitive context values across each log record.

- Initial Monolog Bundle package documentation.
