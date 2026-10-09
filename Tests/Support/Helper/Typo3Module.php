<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2024 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace Tests\Support\Helper;

use Codeception\Module;
use Composer\Autoload\ClassLoader;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Core\{
    Bootstrap,
    SystemEnvironmentBuilder
};

/**
 * Boots TYPO3 so that tests can use the DI container and everything that depends on it.
 *
 * Without this, TYPO3-specific classes throw as soon as they touch Environment, TYPO3_CONF_VARS or
 * the container. Bootstrapping happens once per suite in _initialize() rather than once per test:
 * a full Bootstrap::init() is expensive, and it is idempotent enough that repeating it per test
 * only costs time.
 *
 * The bootstrap needs a fully configured instance — config/system/settings.php has to be readable
 * and the environment variables it interpolates (TYPO3_ENCRYPTION_KEY, DB_*) have to be set. That
 * holds inside the PHP-FPM container, which is where these suites are meant to run.
 */
class Typo3Module extends Module
{
    /**
     * The booted container, shared by every suite that enables this module within a run.
     */
    protected static ?ContainerInterface $container = null;

    /**
     * HOOK: before the suite's tests are created.
     */
    public function _initialize(): void
    {
        if (self::$container instanceof ContainerInterface) {
            return;
        }

        SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);

        self::$container = Bootstrap::init($this->getClassLoader());
    }

    /**
     * Hands the booted DI container to a test.
     *
     * Most tests will not need it — after the bootstrap, GeneralUtility::makeInstance() resolves
     * through the same container — but services that are only registered in Services.yaml have to
     * be pulled from here.
     *
     * @return ContainerInterface The container built by Bootstrap::init().
     */
    public function getContainer(): ContainerInterface
    {
        if (!self::$container instanceof ContainerInterface) {
            throw new RuntimeException('TYPO3 has not been bootstrapped yet.', 1758300000);
        }

        return self::$container;
    }

    /**
     * Returns the Composer class loader of the installation this test run belongs to.
     *
     * Bootstrap::init() needs the ClassLoader instance so it can register the class aliases of the
     * installed extensions, and only vendor/autoload.php hands it out. Requiring that file by a
     * relative path would tie this helper to one particular install depth — and the path this
     * helper used to carry resolved to packages/culture_portal/vendor/autoload.php, which does not
     * exist — so the vendor directory is derived from where ClassLoader itself was loaded from.
     * Note that the class loader registered in the autoload stack is not usable here:
     * typo3/class-alias-loader replaces it with its own wrapper, which is not a Composer
     * ClassLoader.
     *
     * @return ClassLoader The installation's Composer class loader.
     */
    protected function getClassLoader(): ClassLoader
    {
        $classLoaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();

        if ($classLoaderFile === false) {
            throw new RuntimeException('The Composer class loader could not be located.', 1758300001);
        }

        // vendor/composer/ClassLoader.php -> vendor
        $classLoader = require dirname($classLoaderFile, 2) . '/autoload.php';

        if (!$classLoader instanceof ClassLoader) {
            throw new RuntimeException('vendor/autoload.php did not return a Composer class loader.', 1758300002);
        }

        return $classLoader;
    }
}
