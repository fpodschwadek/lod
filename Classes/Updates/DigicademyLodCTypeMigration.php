<?php

declare(strict_types=1);

namespace Digicademy\Lod\Updates;

use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\AbstractListTypeToCTypeUpdate;

#[UpgradeWizard('digicademyLodCTypeMigration')]
final class DigicademyLodCTypeMigration extends AbstractListTypeToCTypeUpdate
{
    public function getTitle(): string
    {
        return 'Migrate "Digicademy Lod" plugins to content elements.';
    }

    public function getDescription(): string
    {
        return 'The "Digicademy Lod" plugins are now registered as content element. Update migrates existing records and backend user permissions.';
    }

    /**
     * Maps the legacy "tt_content.list_type" plugin signatures of this extension to the
     * "tt_content.CType" values they are registered under since TYPO3 13.4.
     *
     * The signatures are unchanged — only the column they live in changes — so every
     * entry maps onto itself. Keep this list in sync with the ExtensionUtility::registerPlugin()
     * calls in Configuration/TCA/Overrides/tt_content.php.
     *
     * @return array<string, string>
     */
    protected function getListTypeToCTypeMapping(): array
    {
        return [
            'lod_api' => 'lod_api',
            'lod_serializer' => 'lod_serializer',
            'lod_vocabulary' => 'lod_vocabulary',
        ];
    }
}
