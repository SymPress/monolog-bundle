# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.1.9 — 2026-11-23

- Send default and security audit log records to PHP's error log when today's log file exists but cannot be written, for example because WP-CLI or cron created it as another Unix user. Log calls no longer throw, so logins and other audited hooks keep working.
- Mask `DB_PASSWORD`, the WordPress salts and other known secrets shorter than 8 characters only as whole words. A local `DB_PASSWORD=db` no longer garbles words like `wpdb`.
- Mask context values under `PHP_AUTH_PW`, `PHP_AUTH_DIGEST`, WordPress reset keys (`key`, `rp_key`, `user_activation_key`), `*_KEY` names, `auth`, `jwt`, `otp`, `signature` and a key named `code`.
- Escape ANSI escape sequences and other control characters except tab, including NEL and U+2028/U+2029, so log input cannot drive a terminal.
- Let Dependabot update the GitHub Actions pins.

## 1.1.8 — 2026-11-16

- Log only the origin of failed outgoing WP HTTP API requests. Slack and Discord webhook URLs and Telegram bot tokens carry their credential in the path and no longer reach the log.
- Scrub values found under sensitive context keys from the whole record only when they have at least 8 characters. Shorter values stay redacted at their key and can no longer erase or garble other parts of the entry.
- Escape CR and LF in messages and context strings, so input cannot forge log lines when `include_stacktraces` keeps inline line breaks.
- Pin the shared CI workflows to `sympress/workflows` v1.3.2.

## 1.1.7 — 2026-11-14

- Skip services whose class cannot be loaded in the redaction pass, for example classes that extend `WP_List_Table`, which WordPress loads only in the admin. Compiling the container no longer stops with a fatal error.

## 1.1.6 — 2026-11-14

- Send the default and security audit logs to PHP's error log when the log directory cannot be written, for example in a read-only release, instead of throwing on every log call.

## 1.1.5 — 2026-11-06

- Use the Symfony 8.1.11 security patches released on November 4.

## 1.1.4 — 2026-10-05

- Accept missing and malformed WordPress audit-hook arguments without interrupting login. Preserve bounded IDs, roles and plugin names without recording raw credentials.

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
