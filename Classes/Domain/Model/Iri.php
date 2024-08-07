<?php
namespace Digicademy\Lod\Domain\Model;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) Torsten Schrade <Torsten.Schrade@adwmainz.de>, Academy of Sciences and Literature | Mainz
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

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Annotation as Extbase;
use TYPO3\CMS\Extbase\Annotation\ORM\Lazy;
use Digicademy\Lod\Domain\Model\IriNamespace;
use Digicademy\Lod\Domain\Repository\StatementRepository;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class Iri extends AbstractEntity
{

    /**
     * type
     *
     * @var integer
     */
    protected $type;

    /**
     * label
     *
     * @var string
     */
    protected $label = '';

    /**
     * labelLanguage
     *
     * @var string
     */
    protected $labelLanguage = '';

    /**
     * comment
     *
     * @var string
     */
    protected $comment = '';

    /**
     * commentLanguage
     *
     * @var string
     */
    protected $commentLanguage = '';

    /**
     * namespace
     *
     * @var \Digicademy\Lod\Domain\Model\IriNamespace
     */
    protected $namespace;

    /**
     * value
     *
     * @var string
     * @Extbase\Validate("NotEmpty")
     */
    protected $value;

    /**
     * record
     *
     * @var string
     */
    protected $record;

    /**
     * Document representations for the subject
     *
     * @var \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Digicademy\Lod\Domain\Model\Representation> $representations
     * @Lazy
     */
    protected $representations;

    /**
     * Statements with this IRI as subject
     *
     * @var \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Digicademy\Lod\Domain\Model\Statement> $statement
     * @Lazy
     */
    protected $statements;

    /**
     * Inverse statements with this IRI as object
     *
     * @var \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Digicademy\Lod\Domain\Model\Statement> $statement
     * @Lazy
     */
    protected $inverseStatements;

    public function __construct(
        protected readonly StatementRepository $statementRepository
    ) {}

    /**
     * Returns the type
     *
     * @return integer type
     */
    public function getType(): ?int
    {
        return $this->type;
    }

    /**
     * Sets the type
     *
     * @param string $type
     *
     * @return void
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * Returns the label
     *
     * @return string $label
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * Sets the label
     *
     * @param string $label
     *
     * @return void
     */
    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * Returns the labelLanguage
     *
     * @return string $labelLanguage
     */
    public function getLabelLanguage(): ?string
    {
        return $this->labelLanguage;
    }

    /**
     * Sets the labelLanguage
     *
     * @param string $labelLanguage
     *
     * @return void
     */
    public function setLabelLanguage(string $labelLanguage): void
    {
        $this->labelLanguage = $labelLanguage;
    }

    /**
     * Returns the comment
     *
     * @return string $comment
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Sets the comment
     *
     * @param string $comment
     *
     * @return void
     */
    public function setComment(string $comment): void
    {
        $this->comment = $comment;
    }

    /**
     * Returns the commentLanguage
     *
     * @return string $commentLanguage
     */
    public function getCommentLanguage(): ?string
    {
        return $this->commentLanguage;
    }

    /**
     * Sets the commentLanguage
     *
     * @param string $commentLanguage
     *
     * @return void
     */
    public function setCommentLanguage(string $commentLanguage): void
    {
        $this->commentLanguage = $commentLanguage;
    }

    /**
     * Returns the namespace
     *
     * @return IriNamespace $namespace
     */
    public function getNamespace(): ?IriNamespace
    {
        return $this->namespace;
    }

    /**
     * Sets the namespace
     *
     * @param IriNamespace $namespace
     *
     * @return void
     */
    public function setNamespace(IriNamespace $namespace): void
    {
        $this->namespace = $namespace;
    }

    /**
     * Returns the value
     *
     * @return string $value
     */
    public function getValue(): ?string
    {
        return $this->value;
    }

    /**
     * Sets the value
     *
     * @param string $value
     *
     * @return void
     */
    public function setValue(string $value): void
    {
        $this->value = $value;
    }

    /**
     * Returns the record
     *
     * @return string $record
     */
    public function getRecord(): ?string
    {
        return $this->record;
    }

    /**
     * Sets the record
     *
     * @param string $record
     *
     * @return void
     */
    public function setRecord(string $record): void
    {
        $this->record = $record;
    }

    /**
     * Returns the representations
     *
     * @return \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Digicademy\Lod\Domain\Model\Representation> $representations
     */
    public function getRepresentations()
    {
        return $this->representations;
    }

    /**
     * Sets the representations
     *
     * @param \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Digicademy\Lod\Domain\Model\Representation> $representations
     *
     * @return void
     */
    public function setRepresentations($representations): void
    {
        $this->representations = $representations;
    }

    /**
     * Returns valid statements where neither part is null (due to hidden IRIs etc.)
     *
     * @return \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Digicademy\Lod\Domain\Model\Statement> $statements
     */
    public function getStatements()
    {
        $statementObjectStorage = GeneralUtility::makeInstance(ObjectStorage::class);

        foreach ($this->statements as $statement) {
            if ($statement->getPredicate() !== null &&
                $statement->getObject() !== null) {
                    $statementObjectStorage->attach($statement);
            }
        }

        $this->statements = $statementObjectStorage;

        return $this->statements;
    }

    /**
     * Sets the statements
     *
     * @param \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Digicademy\Lod\Domain\Model\Statement> $statements
     *
     * @return void
     */
    public function setStatements($statements): void
    {
        $this->statements = $statements;
    }

    /**
     * Returns valid inverse statements where neither part is null (due to hidden IRIs etc.)
     *
     * @return \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Digicademy\Lod\Domain\Model\Statement> $inverseStatements
     */
    public function getInverseStatements()
    {
        $objectStorage = GeneralUtility::makeInstance(ObjectStorage::class);
        $inverseStatements = $this->statementRepository->findByPosition('object', $this);

        if ($inverseStatements) {
            foreach ($inverseStatements as $inverseStatement) {
                if ($inverseStatement->getSubject() !== null &&
                    $inverseStatement->getPredicate() !== null &&
                    $inverseStatement->getObjectInversion()
                    ) {
                        $subject = $inverseStatement->getSubject();
                        $object = $inverseStatement->getObject();
                        $inverseStatement->setSubject($object);
                        $inverseStatement->setObject($subject);
                        $objectStorage->attach($inverseStatement);
                }
            }
        }

        $this->inverseStatements = $objectStorage;
        return $this->inverseStatements;
    }
}
