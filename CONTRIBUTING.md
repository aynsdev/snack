# Contributing to Snack

Thanks for helping. This guide covers how to get a change merged.

## Before you start

- For anything bigger than a small fix, open an issue first so we can agree on the approach.
- Security problems go through [SECURITY.md](SECURITY.md), not public issues.

## Workflow

1. Fork the repo and branch from `main` (`feat/...`, `fix/...`, `chore/...`).
2. Run `composer setup` once, then `composer dev`.
3. Make your change. Every change needs a test: Pest for PHP (`tests/Feature` first), Vitest for frontend logic.
4. Run the checks CI runs:
   ```bash
   vendor/bin/pint
   npm run format && npm run lint
   composer ci:check
   ```
5. Open a pull request against `main` and fill in the template. CI and one maintainer review are needed before merge.

## Conventions

- Follow the patterns in nearby files. `AGENTS.md` has the full project guidelines.
- Use `php artisan make:*` to scaffold classes, migrations and tests.
- Use Wayfinder functions for routes in React code, not hard-coded URLs.
- Don't add or change dependencies without discussing it in an issue first.
- Write commit messages in plain language that says what changed.

## License

By contributing, you agree that your contributions are licensed under the [MIT license](LICENSE).
