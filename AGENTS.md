# Laraql

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `medyat/laraql`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.

## Project Rules

- The product is the protocol: `docs/protocol.md` is the contract, `schema/laraql-v1.schema.json` validates it, `fixtures/v1/` holds request/response pairs. Change the protocol first, then the code; never the other way round.
- Columns are typed on the server (`Column::string`, `::date`, `::money`, …). Operator semantics depend on the column type; unknown operators, options and filter columns are 422. Unknown columns in `fields` and `sort` are skipped and reported in the result's `ignored`, never dropped silently: they cannot change which rows match, a dropped filter can.
- Keep the core free of application concerns: tenant columns, ability flags, photos and user-specific scopes come from hooks (`Source::scope()`, `Source::decorators()`, `options`).
- Requires PHP 8.4+ and Laravel 13+. Property hooks and asymmetric visibility are fine.
- SQLite is the fast loop; dialect-specific behaviour (ILIKE, JSON paths, casts) is covered by the PostgreSQL CI job. Do not write SQL that only one of them understands without a dialect switch.
- Design background (Turkish): https://claude.ai/artifact/G5rUviV6CkENSQuksL5Q72
