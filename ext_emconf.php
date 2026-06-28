<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PPL Rights Management',
    'description' => 'TYPO3 backend module for delegated backend user, group, module and mount rights management.',
    'category' => 'module',
    'author' => 'Pawel Pliousnin',
    'author_email' => 'pliousnin@ppl-ds.com',
    'state' => 'stable',
    'version' => '14.3.0',
    'clearCacheOnLoad' => 0,
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'backend' => '14.3.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
