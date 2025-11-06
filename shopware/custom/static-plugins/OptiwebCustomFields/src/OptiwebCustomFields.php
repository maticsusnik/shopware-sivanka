<?php declare(strict_types=1);

namespace OptiwebCustomFields;

use OptiwebCustomFields\Services\CustomFieldsManager;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class OptiwebCustomFields extends Plugin
{

    public function postInstall(InstallContext $installContext): void
    {
        parent::install($installContext);
        $context = $installContext->getContext();
        CustomFieldsManager::create
        ($this->getCustomFieldsConfig(), $this->container, $context);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        $context = $uninstallContext->getContext();
        // CustomFieldsManager::remove($this->getCustomFieldsConfig(), $this->container, $context);
    }

    private function getCustomFieldsConfig(): array
    {
        return array_filter([
            $this->customerCustomFields(),
            $this->productCustomFields(),
            $this->categoryCustomFields(),
        ], function($field) {
            return !empty($field);
        });
    }

    private function customerCustomFields(): array
    {
        return [];
        // return [
        //     'name' => 'custom_customer',
        //     'config' => [
        //         'label' => [
        //             'en-GB' => 'Customer custom fields',
        //             'sl-SI' => 'Customer custom fields',
        //         ],
        //     ],
        //     'customFields' => [
        //         [
        //             'name' => 'vascoSifra',
        //             'type' => CustomFieldTypes::INT,
        //             'config' => [
        //                 'label' => [
        //                     'en-GB' => 'Vasco sifra',
        //                     'sl-SI' => 'Vasco sifra',
        //                 ],
        //                 'customFieldPosition' => 10
        //             ],
        //             'active' => true
        //         ],
        //         [
        //             'name' => 'vascoShowPrices',
        //             'type' => CustomFieldTypes::CHECKBOX,
        //             'config' => [
        //                 'label' => [
        //                     'en-GB' => 'Stranki prikaži cene',
        //                     'sl-SI' => 'Stranki prikaži cene',
        //                 ],
        //                 'customFieldPosition' => 11
        //             ],
        //             'active' => true
        //         ],
        //         [
        //             'name' => 'vascoMaticna',
        //             'type' => CustomFieldTypes::TEXT,
        //             'config' => [
        //                 'label' => [
        //                     'en-GB' => 'Vasco maticna',
        //                     'sl-SI' => 'Vasco maticna',
        //                 ],
        //                 'customFieldPosition' => 13
        //             ],
        //             'active' => true
        //         ]
        //     ],
        //     'relations' => [
        //         [
        //             'entityName' => 'customer',
        //         ],
        //     ]
        // ];
    }

    private function productCustomFields(): array
    {
        return [
            'name' => 'custom_product',
            'config' => [
                'label' => [
                    'en-GB' => 'Product Settings',
                    'sl-SI' => 'Nastavitve izdelka',
                ],
            ],
            'customFields' => [
                [
                    'name' => 'custom_product_notSellable',
                    'type' => CustomFieldTypes::CHECKBOX,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Not Sellable',
                            'sl-SI' => 'Ni na prodaj',
                        ],
                        'customFieldPosition' => 10
                    ],
                    'active' => true
                ]
            ],
            'relations' => [
                [
                    'entityName' => 'product',
                ],
            ]
        ];
    }

    private function categoryCustomFields(): array
    {
        return [
            'name' => 'custom_category',
            'config' => [
                'label' => [
                    'en-GB' => 'Category Settings',
                    'sl-SI' => 'Nastavitve kategorije',
                ],
            ],
            'customFields' => [
                [
                    'name' => 'custom_category_notSellable',
                    'type' => CustomFieldTypes::CHECKBOX,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Not Sellable (All Products in Category)',
                            'sl-SI' => 'Ni na prodaj (Vsi izdelki v kategoriji)',
                        ],
                        'customFieldPosition' => 10
                    ],
                    'active' => true
                ]
            ],
            'relations' => [
                [
                    'entityName' => 'category',
                ],
            ]
        ];
    }

}
