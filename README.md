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

Test can be found in the `Tests` folder, together with the `codeception.yml` and the suite configurations. Example test commands given here are supposed to run from the root folder of the project application.

```bash
vendor/bin/codecept run Unit -c packages/lod
```

So far, this extension provides two test suites:

- `Unit` — tests that run without TYPO3 being booted. They don't need a configured instance or a database. This means that they can also run in, e.g., a dedicated Docker container for PHP testing that has the application's `vendor/` mounted.
- `Integration` — tests that need a booted TYPO3: the DI container, TCA, TypoScript or the `ContentObjectRenderer`. `Tests/Support/Helper/Typo3Module.php` boots TYPO3 against the configured instance, including its database, so these tests run only if the project application is up and running.

Run a single suite, a single test class or every suite of this package:

```bash
vendor/bin/codecept run Unit -c packages/lod
```

```bash
vendor/bin/codecept run Integration -c packages/lod
```

```bash
vendor/bin/codecept run Unit -c packages/lod ViewHelpers/EscapeLiteralViewHelperTest.php
```

```bash
vendor/bin/codecept run -c packages/lod
```

For displaying debugging messages, add the `--debug` option. After changing the modules of a suite, regenerate the actor classes with `vendor/bin/codecept build -c packages/lod`.

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

