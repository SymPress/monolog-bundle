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

Exceptions retain sanitized messages, file/line and stack frame locations and call
names, including chained exceptions. Stack arguments and objects are omitted.
The same bounded sanitizer is used by the profiler bridge. Constructor defaults
and handler/logger aliases are unchanged.

`MonologExtension` accepts Symfony-style configuration below `monolog`. Omitting
`type` creates a `null` handler. Handler names must be non-empty strings.

## Common keys

| Key | Default | Contract |
|---|---|---|
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
|---|---|---|
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
