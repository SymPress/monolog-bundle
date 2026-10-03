# Handler and DI configuration

## Diagnostic redaction

All configured/default handlers mask credential substrings before formatting.
Messages retain their operation and SQL error code; quoted SQL literals, URL
credentials/query values and authentication payloads are masked. Values supplied
under sensitive context keys are also masked wherever they occur in that record.
`ContextSanitizer` accepts an optional list of known literal secrets, and protects
WordPress authentication keys/salts and `DB_PASSWORD` from constants/environment.
Unlabelled arbitrary secrets must be supplied as known literals or sensitive
context; the sanitizer cannot infer every application-specific credential.

The `pass` shorthand matches key components such as `DB_PASS` and `smtpPass`,
including common compact aliases such as `dbpass` and `userpass`. Diagnostic keys
such as `bypass` and `tests_passed` retain their values and do not add those values
to the known secrets. Passwords, passphrases, tokens and WordPress session cookies
remain masked, including known short credential values.

Exceptions retain sanitized messages, file/line and stack frame locations and call
names, including chained exceptions. Stack arguments and objects are omitted.
The same bounded sanitizer is used by the profiler bridge. Constructor defaults
and handler/logger aliases are unchanged.

`MonologExtension` accepts Symfony-style configuration below `monolog`. Omitting
`type` creates a `null` handler. Handler names must be non-empty strings.

## Common keys

| Key | Default | Contract |
| --- | --- | --- |
| `enabled` | `true` | Disabled handlers remove the default alias and are not attached to loggers. |
| `priority` | `0` | Lower values are attached first; equal values keep reverse declaration order. |
| `level` | `debug` | Minimum Monolog level where the handler supports one. |
| `bubble` | `true` | Passed to handlers that support bubbling. |
| `channels` | all | Inclusive names (`[security]`) or exclusive names (`['!database']`), never both. |
| `formatter` | none | Service ID passed to `setFormatter()`. |
| `nested` | `false` | Excludes a handler from root logger stacks. Wrapper/group references do this automatically. |
| `process_psr_3_messages` | automatic | Boolean or `{enabled, date_format, remove_used_context_fields}`. Enabled automatically only on leaf handlers. |
| `include_stacktraces` / `base_path` | `false` / none | Configure compatible formatters after construction. |

## Supported handler types

| Type | Required keys | Type-specific keys and defaults |
| --- | --- | --- |
| `stream` | — | `path=%kernel.logs_dir%/%kernel.environment%.log`, `file_permission=null`, `use_locking=false` |
| `rotating_file` | — | stream keys plus `max_files=0`, `date_format=Y-m-d`, `filename_format={filename}-{date}` |
| `fingers_crossed` | `handler` | `action_level=warning`, `activation_strategy`, `stop_buffering=true`, `passthru_level=null`, `buffer_size=0`, `excluded_http_codes=[]` |
| `filter` | `handler` | either `accepted_levels` or `min_level=debug` / `max_level=emergency`; do not combine both forms |
| `buffer` | `handler` | `buffer_size=0`, `flush_on_overflow=false` |
| `deduplication` | `handler` | `store=%kernel.cache_dir%/...`, `deduplication_level=error`, `time=60` |
| `sampling` | `handler` | `factor=1` |
| `group` | `members` | list of handler names |
| `whatfailuregroup` | `members` | list of handler names; suppresses member failures |
| `fallbackgroup` | `members` | list of handler names; tries members in order |
| `service` | `id` | existing handler service ID |
| `syslog` | — | `ident=php`, `facility=LOG_USER`, `logopts=LOG_PID` |
| `syslogudp` | `host` | `port=514`, `ident=php`, `facility=LOG_USER` |
| `console` | — | `verbosity_levels=[]`, `console_formatter_options=[]`, `interactive_only=false` |
| `browser_console`, `chromephp`, `firephp`, `test` | — | common `level` and `bubble` |
| `null` | — | common `level` |
| `noop` | — | no constructor options |
| `error_log` | — | `message_type=0`, common `level` and `bubble` |
| `native_mailer` | `to_email`, `from_email`, `subject` | optional `headers=[]`, common `level` and `bubble` |
| `socket` | `connection_string` | `persistent=false`, `timeout=0.0`, `connection_timeout=null` |
| `slackwebhook` | `webhook_url` | `channel=null`, `bot_name=Monolog`, attachment/icon/extra/excluded-field options |

`handler` and `members` contain configuration names, not configured service IDs.
Prefixing a name with `monolog.handler.` is accepted.

## Container contract

For configured name `<name>` the extension builds
`monolog.configured_handler.<name>` and maps `monolog.handler.<name>` to it.
The compiler passes consume:

- `monolog.handlers_to_channels` for root handler/channel routing;
- `monolog.disabled_handlers` to remove replaced defaults;
- `monolog.handler_aliases` to expose configured handlers;
- `monolog.additional_channels` for channel logger creation.

Defaults, formatters, WordPress hook loggers, and the public `logger` aliases live
in `Resources/config/services.yaml`. The profiler bridge publishes normalized,
sanitized entries through the `sympress_profiler_log_entries` WordPress filter;
that string and payload are shared contracts with `sympress/profiler`.

## WordPress security audit

The public bundle supplies `monolog.logger.security` and a built-in audit recorder.
It does not require the optional profiler or a security package. Defaults send
the security channel to `monolog.handler.security_audit`, a separate rotating
`%kernel.logs_dir%/security-YYYY-MM-DD.log` file at `info`, with mode `0600`, locking
and 14 retained files. Successful logins and changes are therefore captured even
when the production application handler starts at `warning`. The log directory
must be private and outside HTTP access. Retention and access remain deployment
responsibilities; this is a local operational log, not a tamper-proof ledger.

```yaml
monolog:
    security_audit:
        enabled: true
        path: '%kernel.logs_dir%/security.log'
```

`enabled` is a boolean (default `true`) controlling only the built-in WordPress
recorder. `path` is a non-empty string for the default rotating audit handler.
The security channel remains available to application code when the recorder is
disabled. Override the `security_audit` handler by its normal configuration name
to route the channel elsewhere or to change retention. Disabling that handler
removes the dedicated sink; handler/channel configuration must still capture
`info` events if an audit trail is required. Explicit `kernel.logs_dir: false`
continues to disable default file logging.

Every record has a stable `event`, numeric `actor_id` and `site_id` (zero when
unknown). Login success adds the confirmed `user_id`; failure adds only a fixed
allowlisted reason, falling back to `authentication_failed` for custom errors.
There is no lookup from the attempted identifier. Usernames, passwords, cookies,
IP addresses, error messages/data and raw old/new option values are never inputs
to the audit payload. Role lists are limited to 20 entries of 64 ASCII identifier
characters; plugin paths to 190 safe relative characters; theme directory slugs
to 64 identifier characters. Invalid identifiers become `[invalid]`.

| Hook | Event | Additional fields |
| --- | --- | --- |
| [`wp_login`](https://developer.wordpress.org/reference/hooks/wp_login/) | `login.succeeded` | confirmed `user_id` |
| [`wp_login_failed`](https://developer.wordpress.org/reference/hooks/wp_login_failed/) | `login.failed` (`warning`) | fixed `reason` |
| [`set_user_role`](https://developer.wordpress.org/reference/hooks/set_user_role/), `add_user_role`, `remove_user_role` | `user.role_set`, `user.role_added`, `user.role_removed` | `user_id`, role, bounded previous roles for set |
| [`activated_plugin`](https://developer.wordpress.org/reference/hooks/activated_plugin/), `deactivated_plugin` | `plugin.activated`, `plugin.deactivated` | relative plugin path, `network_wide` |
| [`switch_theme`](https://developer.wordpress.org/reference/hooks/switch_theme/) | `theme.switched` | new and previous directory slugs |
| [`updated_option`](https://developer.wordpress.org/reference/hooks/updated_option/) | `option.updated` | allowlisted option name |

The option allowlist is `siteurl`, `home`, `admin_email`, `new_admin_email`,
`users_can_register`, `default_role`; an event records a completed change without
its values. WordPress may emit individual add/remove role events and a set event
for the same operation. Silent plugin changes, direct SQL/file edits and tools
that bypass these WordPress hooks are outside this recorder's coverage.

Production redaction preserves exception class, error location and stack call
names while omitting argument/object contents and masking SQL string literals,
known credentials and URL credentials/query data. Named credentials include
alternate API/private/access-key spellings, PHP sessions and custom WordPress
authentication-cookie constants. Application code must still avoid logging
unlabelled secrets that no generic redactor can identify.

For a real-core hook and DI smoke check, run
`php tests/Integration/wordpress-security-audit.php /absolute/disposable/wp`.
It requires an installed disposable WordPress database with an `audit-admin`
administrator, creates/reuses fixture user/plugins/themes, changes site options,
and suppresses mail. Never run it against an existing site. It verifies confirmed
and failed login, roles, activations, completed option changes, private files,
the production level split and absence of sentinel secrets through the actual
kernel hook compiler and WordPress APIs.
