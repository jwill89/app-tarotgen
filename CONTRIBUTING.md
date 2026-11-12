# Contributing to TarotGen.io

Thanks for your interest in contributing! TarotGen.io is a Vue 3 SPA + PHP
(Slim 4) REST API for generating and sharing tarot readings. This guide covers
**how to propose and submit changes**. For project orientation and architecture,
start with these documents and keep them close while you work:

- [`README.md`](README.md) — quick orientation, where things live, dev quickstart.
- [`AGENTS.md`](AGENTS.md) — the deep guide: architecture, the backend layering
  rule, conventions, and gotchas.
- [`shared/standards/README.md`](shared/standards/README.md) — the rules every jwill89 project follows, which AGENTS.md builds on.

## Code of conduct

Be respectful and constructive. Assume good faith, keep discussion focused on
the work, and help newcomers. Harassment or hostility isn't welcome.

## Ways to contribute

- **Report a bug** — open an issue with steps to reproduce, what you expected,
  what happened, and your environment (OS, PHP/Node versions, browser).
- **Suggest a feature** — open an issue describing the problem you're solving
  before writing code, so we can agree on the approach.
- **Improve docs** — fixes to the README, AGENTS guide, code comments, or this
  file are always welcome.
- **Send a fix or feature** — see the steps below.

> For anything beyond a small, obvious fix, **open an issue first**. It saves you
> from building something that doesn't fit, and gives us a place to agree on
> scope before review.

## Submitting a change

The owner works on `main`, with no branches, and CI on GitHub is the gate. A change from outside comes in as a pull request from a fork:

1. Fork the repository and set it up as the [README Quickstart](README.md#quickstart-local-dev) says.
2. Make the change, with its tests, in small, focused commits.
3. Run `task check` from the repo root. It runs every lint, audit and test that CI runs.
4. If a user could notice the change, add a line under `## [Unreleased]` in [`CHANGELOG.md`](CHANGELOG.md).
5. Open a pull request against `main`. CI runs on it.

## Reporting security issues

**Please do not open public issues for security vulnerabilities.** Report them
privately to the maintainer (see the repository owner's contact on
[GitHub](https://github.com/jwill89/app-tarotgen)) so a fix can ship before
disclosure. Authentication, session handling, CSRF (`OriginGuard`), rate
limiting, and the Turnstile/secret configuration are especially sensitive areas
— flag anything you find in those.

## License

This project is **proprietary** software — see [`LICENSE`](LICENSE). It is
source-available for reference and contribution, but it is not open source: you
may not reuse, redistribute, or deploy the code without the Owner's written
permission.

By submitting a contribution you assign all right, title, and interest in that
contribution to the Owner, so it can be incorporated into the project under the
proprietary license.

---

Thanks again for contributing! 🔮
