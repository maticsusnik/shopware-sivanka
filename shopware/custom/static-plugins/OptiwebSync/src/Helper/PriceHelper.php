<?php

namespace OptiwebSync\Helper;

use OptiwebSync\Core\Content\OwCustomerProductPrices\OwCustomerProductPricesEntity;
use Shopware\Core\Checkout\Cart\Price\GrossPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Cart\Price\NetPriceCalculator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PriceHelper
{
    public static function calculateTaxFromNetPrice($price, TaxRuleCollection $taxRules, NetPriceCalculator $netPriceCalculator, Context $context, bool $getFirstTax = false)
    {
        $definition = new QuantityPriceDefinition(
            floatval($price),
            new TaxRuleCollection($taxRules),
            1
        );

        $taxes = $netPriceCalculator->calculate($definition, $context->getRounding());

        if ($getFirstTax) {
            return $taxes->getCalculatedTaxes()->first()->getTax();
        }
        return $taxes;
    }

    /**
     * @param CustomerEntity $customer
     * @param array $products
     * @param EntityRepository $publicPricesB2bRepository
     * @param SalesChannelContext $context
     * @param NetPriceCalculator $netPriceCalculator
     * @return array
     */
    public static function getCustomerSpecialPrices(CustomerEntity $customer, int $store, array $products, EntityRepository $publicPricesB2bRepository, SalesChannelContext $context, NetPriceCalculator $netPriceCalculator): array
    {
        $customerId = $customer->getId();
        $isGross = $context->getCurrentCustomerGroup()->getDisplayGross();
        if (!count($products)) return [];

        if (gettype(end($products)) === "object") {
            $productIds = array_map(function (SalesChannelProductEntity $product) {
                return $product->getId();
            }, $products);
        }
        else{
            $productIds = $products;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter("customerId", $customerId));
        $criteria->addFilter(new EqualsAnyFilter("productId", $productIds));
        $criteria->addFilter(new EqualsFilter("store", $store));
        $criteria->addAssociation("product");

        $pricesResult = $publicPricesB2bRepository->search($criteria, $context->getContext());
        $allCustomerPrices = $pricesResult->getEntities();
        $allCustomerPricesHash = [];

        /** @var OwCustomerProductPricesEntity $singleCustomerPrice */
        foreach ($allCustomerPrices as $singleCustomerPrice) {
            $allCustomerPricesHash[$singleCustomerPrice->getProductId()] = self::calculateGrossPrice($singleCustomerPrice,  $isGross, $netPriceCalculator, $context->getContext());
        }

        return $allCustomerPricesHash;
    }
    public static function getEntityFromRepositoryById(EntityRepository $repository, $id, Context $context)
    {
        $criteria = new Criteria([$id]);
        $result = $repository->search($criteria, $context);
        return $result->first();
    }

    public static function calculateGrossPrice(OwCustomerProductPricesEntity $productCustomerPriceB2bEntity, $isGross, NetPriceCalculator $netPriceCalculator, Context $context) : OwCustomerProductPricesEntity
    {
        if (!$isGross) return $productCustomerPriceB2bEntity;
        $taxEntity = $productCustomerPriceB2bEntity->getProduct()->getTax();
        $taxRule = new TaxRule($taxEntity->getTaxRate(), 100);
        $taxes = new TaxRuleCollection([$taxRule]);


        /* Multiply by FACTOR_UP to get correct result for decimal products. Then divide by FACTOR_UP to reset the numbers to correct decimal point */

        $taxData = self::calculateTaxFromNetPrice($productCustomerPriceB2bEntity->getPrice() * 100, $taxes, $netPriceCalculator, $context);
        $tax = $taxData->getCalculatedTaxes()->getAmount() / 100;
        $productCustomerPriceB2bEntity->setPrice($productCustomerPriceB2bEntity->getPrice() + $tax);
        return $productCustomerPriceB2bEntity;
    }
}
