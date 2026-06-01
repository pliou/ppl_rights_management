<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PPL Rights Management',
    'description' => 'TYPO3 backend module for delegated backend user, group, module and mount rights management.',
    'category' => 'module',
    'author' => 'Pawel Pliousnin',
    'author_email' => 'pliousnin@ppl-ds.com',
    'state' => 'stable',
    'version' => '13.4.1',
    'clearCacheOnLoad' => 0,
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'backend' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
