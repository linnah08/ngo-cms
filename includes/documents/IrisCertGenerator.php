<?php
require_once __DIR__ . '/DonationCertGenerator.php';

class IrisCertGenerator extends DonationCertGenerator {

    const IRIS = [
        'name'         => 'ЦЕНТЪР ЗА РЕХАБИЛИТАЦИЯ И СОЦИАЛНИ КОНТАКТИ НА ДЕЦА И ЛИЦА СЪС ЗРИТЕЛНИ УВРЕЖДАНИЯ',
        'address'      => 'БЪЛГАРИЯ, гр. Варна (9023), р-н Владислав Варненчик, жк. Вл.Варненчик, ул. Панайот Кърджиев, 5',
        'mol'          => 'Анелия Тимофеева',
        'eik'          => '104686779',
        'bank'         => 'БАНКА ДСК ЕАД',
        'bic'          => 'STSABGSF',
        'iban'         => 'BG23STSA93000032143827',
        'phone'        => '',
        'email'        => '',
        'website'      => '',
        'registration' => '',
    ];

    protected function getOrg(): array {
        return self::IRIS;
    }

    protected function getOrgText(bool $en): array {
        return [
            'reg_note'  => '',
            'intro'     => $en
                ? 'This certificate is issued by CSRI Iris, Varna, confirming receipt of a donation under the following terms:'
                : 'С настоящия сертификат ЦСРИ Ирис, гр. Варна, удостоверява, че е получила дарение при следните условия:',
            'sig_label' => $en ? 'Director of CSRI Iris:' : 'Директор на ЦСРИ Ирис:',
        ];
    }
}
