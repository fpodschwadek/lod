<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Linked Open Data for TYPO3',
    'description' => 'Provides a semantic layer for a TYPO3 with LOD API, terminology service, RDF serializer and IRI resolver',
    'author' => 'Torsten Schrade',
    'author_email' => 'Torsten.Schrade@adwmainz.de',
    'author_company' => 'Academy of Sciences and Literature | Mainz',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
