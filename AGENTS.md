# SymPress Monolog Bundle

## Scope and entry points

- Read `docs/handlers.md` before changing handler configuration.
- `src/DependencyInjection/MonologExtension.php` normalizes config and builds handler services.
- `src/Compiler/` wires channels, processors, and configured aliases.
- `Resources/config/services.yaml` owns default services, handlers, formatters, and WordPress hooks.

## Verification

- Fast behavior check: `composer tests`.
- Full required check: `composer qa`.
- Handler/config changes need a compile-and-behavior case in `MonologExtensionTest`.

## Invariants

- Preserve `monolog.handler.<name>`, configured-handler aliases, channel inclusion/exclusion, priority, and nested-handler semantics.
- Wrapper handlers reference `handler`; group handlers reference `members`. Keep nested handlers out of root logger stacks.
- Preserve the default `logger`/`Psr\Log\LoggerInterface` aliases and tagged channel/processor behavior.
- Sanitize and normalize context before exposing it to profiler output.
- `sympress_profiler_log_entries` is a cross-repository filter contract with `sympress/profiler`.

## Cross-repository impact and done

- The kernel discovers `MonologBundle` and `monolog-bundle/monolog-bundle.php` through `extra.kernel`.
- A change is done when the relevant DI/handler test and `composer qa` pass and `docs/handlers.md` remains exact.
