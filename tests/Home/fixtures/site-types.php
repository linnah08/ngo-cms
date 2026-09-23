<?php
// A site's own home sections, as a theme's 'home_types' file declares them (HomeSiteTypesTest).
return [
    'tst_words' => [
        'icon' => '🔤', 'label' => 'Думи', 'desc' => 'Списък с думи.',
        'fields' => [
            'heading' => ['kind' => 'text', 'label' => 'Заглавие', 'max' => 50],
            'words'   => ['kind' => 'site', 'label' => 'Думи',
                'clean'   => function (mixed $raw, string $key): array {
                    $list = array_values(array_filter(array_map('trim', explode(',', is_string($raw) ? $raw : '')), 'strlen'));
                    return [$list, $list ? [] : [$key => 'Добавете поне една дума.']];
                },
                'render'  => fn(string $key, array $def, mixed $val, array $errors): string =>
                    '<input id="f_' . $key . '" value="' . h(implode(', ', (array) $val)) . '">',
                'uploads' => function (array &$in, array $files, string $sid, string $key): array {
                    if (isset($files['up_words'])) $in[$key] = 'качена';
                    return isset($files['bad']) ? [$key => 'Лош файл.'] : [];
                }],
        ],
    ],
    'tst_clip' => [
        'label'  => 'Клип',
        'fields' => [
            'video' => ['kind' => 'video', 'label' => 'Видео', 'required' => true],
            'thumb' => ['kind' => 'internal', 'label' => 'Картинка'],
        ],
    ],
    // Cannot replace a type the CMS defines, and a key that is not a plain name is skipped.
    'hero'      => ['label' => 'Подменен банер', 'fields' => []],
    'Bad-Key'   => ['label' => 'Лош', 'fields' => []],
    'tst_nofields' => ['label' => 'Без полета'],
];
