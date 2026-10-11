<?php
/**
 * Example of a site's own extensions file. To use it, copy it to
 * includes/site-extensions.php (that name is never part of a release, so
 * updates leave it alone) and keep only what the site needs. Without the file,
 * ngo-cms works exactly as it does out of the box.
 *
 * Every section is optional. A broken entry is logged and skipped.
 * See includes/site_extensions.php for what each key means.
 */
return [
    // Donation recipients. More than one → donors choose who the donation is for.
    // List the organisation itself too; old donations belong to the default.
    'recipients' => [
        'foundation' => [
            'label_bg' => 'Фондация Пример', 'label_en' => 'Example Foundation',
            'purpose_bg' => 'За дейността и програмите на фондацията',
            'purpose_en' => 'For the foundation\'s activities and programmes',
            'default' => true,
        ],
        'partner_centre' => [
            'label_bg' => 'Партньорски център', 'label_en' => 'Partner centre',
            'hint_bg' => 'Дарението отива изцяло за работата на центъра.',
            'hint_en' => 'The donation goes entirely to the centre\'s work.',
            'hint_url' => '/novini/partnyorski-centar/',
            'purpose_bg' => 'За работата на партньорския център',
            'purpose_en' => 'For the partner centre\'s work',
        ],
    ],

    // Admin roles of the site's own. They land on `home` (a page of the site's
    // own) and can open only the admin pages listed in admin_pages.
    'roles' => [
        'partner_admin' => ['label' => 'Партньорски център', 'home' => '/partner/', 'admin_pages' => []],
    ],

    // Extra filter buttons on Админ → Поръчки: an SQL condition on `orders`.
    'order_filters' => [
        'big_orders' => ['label' => 'Над 100 €', 'where' => 'total_eur >= ?', 'params' => [100]],
    ],

    // Extra files for Админ → Месечен отчет and the monthly report email.
    'reports' => [
        'partner' => [
            'label'    => 'Отчет за партньора',
            'filename' => 'partner-%04d-%02d.csv',
            'csv'      => static function (int $year, int $month): string {
                return "Дата,Сума\n";   // build the CSV here
            },
            'summary'  => static fn(int $year, int $month): string => '',   // one line for the email, or ''
        ],
    ],

    // Document kinds the site's own pages issue (numbered with document_sequence_next()).
    'document_types' => [
        'partner_cert' => ['label' => 'Сертификат (партньор)', 'plural' => 'Сертификати за партньора', 'digits' => 5],
    ],
];
