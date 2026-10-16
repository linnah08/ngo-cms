<?php
// A site type written before the list field existed: it still says kind 'cards' (HomeFinalFixesTest).
return [
    'tst_steps' => [
        'label'  => 'Стъпки',
        'fields' => [
            'heading' => ['kind' => 'text', 'label' => 'Заглавие', 'max' => 50],
            'cards'   => ['kind' => 'cards', 'label' => 'Стъпки'],
        ],
    ],
];
