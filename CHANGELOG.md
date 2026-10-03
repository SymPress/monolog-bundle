# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## Unreleased

- Record bounded WordPress security events in a dedicated private rotating audit log, including successful and failed logins, role changes, activations and relevant option changes.
- Mask custom WordPress/PHP session cookies and alternate API/private/access-key context names.

## 1.0.3 — 2026-10-02

- Mask credential and SQL literal substrings while retaining operation diagnostics.
- Preserve exception locations, bounded argument-free stack frames and chained exceptions.
- Mask known credentials and sensitive context values across each log record.

- Initial Monolog Bundle package documentation.
