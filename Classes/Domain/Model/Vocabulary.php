<?php

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

namespace Digicademy\Lod\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class Vocabulary extends AbstractEntity
{
    /**
     * IRI
     *
     * @var Iri
     */
    protected $iri;

    /**
     * label
     *
     * @var string
     */
    protected $label;

    /**
     * comment
     *
     * @var string
     */
    protected $comment = '';

    /**
     * terms
     *
     * @var ObjectStorage<Iri>
     */
    protected $terms;

    /**
     * Returns the graph iri; null if the vocabulary has none (the TCA field is optional)
     *
     * @return Iri|null $iri
     */
    public function getIri(): ?Iri
    {
        return $this->iri;
    }

    /**
     * Sets the graph iri
     *
     * @param Iri $iri
     */
    public function setIri(Iri $iri): void
    {
        $this->iri = $iri;
    }

    /**
     * Returns the label
     *
     * @return string $label
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Sets the label
     *
     * @param string $label
     */
    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * Returns the comment
     *
     * @return string $comment
     */
    public function getComment(): string
    {
        return $this->comment;
    }

    /**
     * Sets the comment
     *
     * @param string $comment
     */
    public function setComment(string $comment): void
    {
        $this->comment = $comment;
    }

    /**
     * Returns the terms
     *
     * @return ObjectStorage<Iri> $terms
     */
    public function getTerms(): ObjectStorage
    {
        return $this->terms;
    }

    /**
     * Sets the terms
     *
     * @param ObjectStorage<Iri> $terms
     */
    public function setTerms(ObjectStorage $terms): void
    {
        $this->terms = $terms;
    }
}
