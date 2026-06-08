# Security Policy

## Supported Versions

Security fixes are provided for the latest released version of this package.

## Reporting a Vulnerability

Please do not open public issues for suspected security vulnerabilities.

Report vulnerabilities by emailing `brian.schaeffner@sympress.de` with:

- A description of the issue and its impact
- Steps to reproduce or a minimal proof of concept
- Affected versions or commits, if known
- Any relevant logs with secrets removed

You should receive an acknowledgement within 72 hours. Confirmed vulnerabilities
will be handled with coordinated disclosure.

## Sensitive Data

Logs may contain request metadata, exception details, user identifiers, and
application context. Avoid logging secrets and review handler destinations before
enabling verbose logging outside development environments.
