# Contributing

Thanks for taking the time to improve SymPress Monolog Bundle.

## Local Setup

```bash
composer install
composer test
composer cs:analyze
composer cs
```

The package uses PHP 8.5, Monolog, Symfony DependencyInjection, PHPUnit,
PHPStan, PHP CS Fixer, and PHPCS with the Inpsyde coding standards.

## Pull Requests

- Keep pull requests focused on one behavior or documentation change.
- Add or update tests for handler, processor, or compiler-pass changes.
- Run the available checks before opening a pull request.
- Use Conventional Commits for commit messages, for example
  `feat(monolog-bundle): add handler configuration`.

## Coding Guidelines

- Keep WordPress-specific behavior behind processors or hook services.
- Prefer service-container configuration over runtime service lookups.
- Sanitize log context before exposing it to profiler output.
- Preserve Monolog naming conventions for handler and channel configuration.
