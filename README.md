# Linked Open Data for TYPO3

Provides a semantic layer for a TYPO3 instance with LOD API, terminology service, RDF serializer and URI resolver.

This is the development repository for the LOD extension. The extension has not yet been officially released to the TYPO3 extension repository but is fully usable.

**TYPO3 version compatibility**:

| Branch | TYPO3    | PHP     | Support                              |
|--------|----------|---------|--------------------------------------|
| main   | 11.5     | 7.4-8.2 | Features, Bugfixes, Security Updates |
| 10.4   | 9.5-10.4 | 7.2-7.4 | None                                 |
| 7.6    | 7.6      | 7.0-7.2 | None                                 |

## Tests, Upgrades, Fixes

This extension comes with a range configurations for analyses, tests, and automatic fixes that work with the tools listed in its Composer dev requirements. All dev requirements should be installed at project level.

All examples given here assume that these tools are executed from the root folder of a TYPO3 project and this extension is located in the `packages` folder for development purposes.

### PHPStan

```bash
vendor/bin/phpstan analyse -c packages/lod/phpstan.neon --memory-limit 2G
```

### Codeception

See https://codeception.com/docs/Introduction for details.

The extension's tests live in this package, under `Tests/`, together with the `codeception.yml` and the suite configurations they need. Tests are always run with this configuration, passing the package with `-c`, never through a configuration that aggregates several extensions. The test tools come from the application's `vendor/`, so every command is run from the application's root folder, inside the container that runs PHP for the application — in the NFDI4Culture Portal, for example, `nfdi4culture_development_php_fpm`:

```bash
docker exec -it <php-container> vendor/bin/codecept run Unit -c packages/lod
```

There are two suites:

- `Unit` — tests that run without TYPO3 being booted. They need no configured instance and no database, so they also run in any other PHP container that has the application's `vendor/` mounted, e.g. a throwaway `1drop/php-utils` container whose tag matches the application's PHP version.
- `Integration` — tests that need a booted TYPO3: the DI container, TCA, TypoScript or the `ContentObjectRenderer`. `Tests/Support/Helper/Typo3Module.php` boots TYPO3 against the configured instance, including its database, so these tests run only in the application's own PHP container.

Run a single suite, a single test class or every suite of this package:

```bash
docker exec -it <php-container> vendor/bin/codecept run Unit -c packages/lod
docker exec -it <php-container> vendor/bin/codecept run Integration -c packages/lod
docker exec -it <php-container> vendor/bin/codecept run Unit -c packages/lod ViewHelpers/EscapeLiteralViewHelperTest.php
docker exec -it <php-container> vendor/bin/codecept run -c packages/lod
```

With a suite named, the path of a test class is relative to that suite's folder (`Tests/Unit/`); a single test method is selected by appending `:methodName` to that path, e.g. `ViewHelpers/EscapeLiteralViewHelperTest.php:testThrowsForUnknownFormat`.

In a standalone checkout of this package with its own dev dependencies installed, `composer test` and `composer test-integration` run the two suites. Installed into an application as a path repository, the package has no `vendor/` of its own; use the commands above.

For displaying debugging messages, add the `--debug` option. After changing the modules of a suite, regenerate the actor classes with `vendor/bin/codecept build -c packages/lod`.

#### Writing tests

Codeception 5 detects tests with PHPUnit's rules but reads their metadata with its own, which makes the two halves easy to mix up:

- Mark a test by prefixing the method name with `test`, or with `#[PHPUnit\Framework\Attributes\Test]`. A `/** @test */` doc comment is **not** picked up — PHPUnit 13 no longer reads doc-comment metadata, and such a test is silently skipped rather than reported.
- Declare data providers with `#[Codeception\Attribute\DataProvider]` or a `@dataProvider` doc comment. PHPUnit's `#[DataProvider]` attribute is **not** picked up, because Codeception resolves providers itself; the test method is then called without arguments.
- PHPUnit only reports PHP warnings and notices raised by the code under test. Development contexts of TYPO3 applications commonly turn warnings into exceptions (`SYS.exceptionalErrors`), so a test that pins down warning-free behaviour wraps the call in `withPhpErrorsAsExceptions()` from `Tests/Support/FailOnPhpErrorsTrait.php`.
- `Unit` tests construct the class under test directly. ViewHelpers get their arguments through `setArguments()` and, where they render children, a closure through `setRenderChildrenClosure()`. Test doubles for collaborators that would need TYPO3 come from `$this->createStub()`.
- `Integration` tests only read and never write: there is no separate test database, so they run against the application's own data. They must not depend on particular records either, so that they keep passing after the database is replaced.
- The suite configurations explain both rules; see the comments in `Tests/Unit.suite.yml` and `Tests/Integration.suite.yml`.

The Codeception namespace is `Tests`, as in the other extensions of the application, and `Tests/Support/Helper/Typo3Module.php` is kept byte-identical to their copies; when one changes, copy it to the others. The shared namespace is safe because only one extension's configuration is loaded per run. No `autoload-dev` mapping is needed: Codeception loads the test and support classes itself.

### TYPO3 Rector
 ```bash
 vendor/bin/rector process --config packages/lod/rector.php
 ```

### PHP-CS Fixer
```bash
composer exec php-cs-fixer fix packages/lod
```

## Research Software Engineering

This software is licensed under the terms of the GNU General Public License v2
as published by the Free Software Foundation.

Copyright <a href="https://orcid.org/0000-0002-0953-2818">Torsten Schrade</a> | <a href="http://www.adwmainz.de">Academy of Sciences and Literature | Mainz</a>

