<?php declare(strict_types=1);

namespace OptiwebCustomFields;

use OptiwebCustomFields\Services\CustomFieldsManager;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
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

    /**
     * The field set is written on install only, so a plugin that is already installed
     * would never learn about a newly added field. Re-running the creator on update
     * upserts by name: existing fields keep their id and their stored values, new ones
     * are added.
     */
    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);
        CustomFieldsManager::create(
            $this->getCustomFieldsConfig(),
            $this->container,
            $updateContext->getContext()
        );
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
                ],
                [
                    'name' => 'custom_product_hidePrice',
                    'type' => CustomFieldTypes::CHECKBOX,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Hide Price',
                            'sl-SI' => 'Skrij ceno',
                        ],
                        'helpText' => [
                            'en-GB' => 'Hides the price and the add-to-cart button. The product can only be enquired about.',
                            'sl-SI' => 'Skrije ceno in gumb za dodajanje v košarico. Za izdelek je mogoče samo povpraševanje.',
                        ],
                        'customFieldPosition' => 20
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
                ],
                [
                    'name' => 'custom_category_hidePrice',
                    'type' => CustomFieldTypes::CHECKBOX,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Hide Prices (All Products in Category)',
                            'sl-SI' => 'Skrij cene (Vsi izdelki v kategoriji)',
                        ],
                        'helpText' => [
                            'en-GB' => 'Hides the price and the add-to-cart button for every product in this category and its subcategories. Those products can only be enquired about.',
                            'sl-SI' => 'Skrije ceno in gumb za dodajanje v košarico za vse izdelke v tej kategoriji in podkategorijah. Za te izdelke je mogoče samo povpraševanje.',
                        ],
                        'customFieldPosition' => 20
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
