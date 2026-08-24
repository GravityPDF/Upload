Contributing
============

Issue tracker
-------------

The issue tracker is for bug reports and feature requests. Please do not use it for general
questions or troubleshooting, and please report one bug or one feature per issue.

Bug reports
-------------

A report has to be reproducible by copy and paste, assuming `composer require gravitypdf/upload`
and nothing else. That means it includes:

* **The input.** The `$_FILES` entry as a literal array, or the `FileInfoInterface` objects
  handed to `FileList`. A filename that triggers the bug matters down to the byte — say so if
  it carries a control character, a bidi mark or invalid UTF-8, since those do not survive a
  paste into a browser.
* **The configuration.** The validations added, and any of the opt-outs in
  [Turning the defaults off](../docs/turning-the-defaults-off.md) that are in play.
* **What happened and what you expected**, as the return value, the `getErrors()` entries or
  the exception — not a description of them.
* **The PHP version**, and whether `ext-mbstring` is loaded. Several behaviours differ across
  the supported 7.3 to 8.5 range, and filename truncation differs without `mbstring`.

Security issues do not belong in the tracker. Report those privately through
[GitHub's security advisories](https://github.com/GravityPDF/Upload/security/advisories/new).

Feature requests
-------------

Label the issue as a feature request and say what problem it solves. This library is a
security boundary before it is a convenience, so a request that relaxes a default needs to say
what it stops applying and why that is acceptable.

Pull requests
-------------

Pull requests target the default [main](https://github.com/GravityPDF/upload/tree/main) branch,
except for backports to older versions. **When you first open a PR, GitHub sets the base to the
upstream `codeguy/upload` repository; you have to change it.**

Before opening one, run the checks CI will run:

```bash
composer phpunit         # the test suite
composer lint            # PHPCS, PSR-12
composer phpstan         # PHPStan level 9, over src and tests
composer check-syntax    # parallel-lint, all PHP files
```

Change an example in `README.md` or `docs/` and two more apply, since those snippets are read
out of the Markdown and executed:

```bash
composer psr7-readme        # docs/psr7.md, against nyholm/psr7 and guzzlehttp/psr7
composer base64-docs        # docs/base64-uploads.md
composer translator-readme  # docs/translation/, against the real translation libraries
```

Change or add an error message and regenerate the catalogue, or the `i18n` workflow fails on
the diff:

```bash
composer i18n:pot
```

Guidelines:

* Use an aptly named feature branch.
* Only the lines within the scope of the pull request should change.
* Make small, atomic commits that keep related changes together.
* Code must be accompanied by a test whenever one can be written. New behaviour on the storage
  or filename path needs a test of the refusal as well as of the acceptance — a rule that
  refuses everything passes a one-sided test.
* User-facing changes need an entry in `CHANGELOG.md` in the same pull request. It is the
  documented record of what breaks between major versions.

Supported PHP versions
-------------

7.3 through 8.5, tested on all eight. Anything that will not parse on 7.3 cannot go in: no
typed properties, no union types, no `mixed`, no arrow functions, no constructor promotion.
Property and parameter types beyond what 7.3 allows live in docblocks.

`ext-fileinfo` is the only required extension. `ext-mbstring` is a suggestion, so every `mb_*`
call has to be guarded by the function that makes it — CI runs the suite with the extension,
with `symfony/polyfill-mbstring`, and with neither.

To update a pull request, push to the same branch rather than opening a new one.
