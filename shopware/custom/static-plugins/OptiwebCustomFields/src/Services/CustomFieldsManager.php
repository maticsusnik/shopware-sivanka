<?php

declare(strict_types=1);

namespace OptiwebCustomFields\Services;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationEntity;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CustomFieldsManager
{
    public static function create(array $customFieldsGroups, ContainerInterface $container, Context $context): void
    {
        // $context = Context::createDefaultContext();

        /** @var EntityRepository $customFieldSetRepository */
        $customFieldSetRepository = $container->get('custom_field_set.repository');
        /** @var EntityRepository $customFieldRepository */
        $customFieldRepository = $container->get('custom_field.repository');
        /** @var EntityRepository $customFieldRelationRepository */
        $customFieldRelationRepository = $container->get('custom_field_set_relation.repository');

        $existingFieldSets = $customFieldSetRepository->search(new Criteria(), $context)->getElements();
        $existingCustomFields = $customFieldRepository->search(new Criteria(), $context)->getElements();
        $existingCustomRelationships = $customFieldRelationRepository->search(new Criteria(), $context)->getElements();

        //loop trough existing fieldSets and check by name if any matches to "name" in $customFields and add id if they match
        /** @var CustomFieldSetEntity $existingFieldSet */
        foreach ($existingFieldSets as $existingFieldSet) {
            foreach ($customFieldsGroups as &$customFieldsGroup) {
                if ($existingFieldSet->getName() === $customFieldsGroup["name"]) {
                    $customFieldsGroup['id'] = $existingFieldSet->getId();
                }
            }
        }

        // Match existing custom fields by name within each group and add ID if they match
        /** @var CustomFieldEntity $existingCustomField */
        foreach ($existingCustomFields as $existingCustomField) {
            foreach ($customFieldsGroups as &$customFieldsGroup) {
                foreach ($customFieldsGroup['customFields'] as &$customField) {
                    if ($existingCustomField->getName() === $customField['name']) {
                        $customField['id'] = $existingCustomField->getId();
                    }
                }
            }
        }

        // Match existing field relationships by entity name and parent set ID, then add ID if they match
        /** @var CustomFieldSetRelationEntity $existingCustomRelation */
        foreach ($existingCustomRelationships as $existingCustomRelation) {
            foreach ($customFieldsGroups as &$customFieldsGroup) {
                foreach ($customFieldsGroup['relations'] as &$relation) {
                    if (
                        $existingCustomRelation->getEntityName() === $relation['entityName'] &&
                        $existingCustomRelation->getCustomFieldSetId() === $customFieldsGroup['id']
                    ) {
                        $relation['id'] = $existingCustomRelation->getId();
                    }
                }
            }
        }

        $customFieldSetRepository->upsert($customFieldsGroups, $context);
    }

    public static function remove(array $customFields, ContainerInterface $container, Context $context): void
    {
        // $context = Context::createDefaultContext();

        /** @var EntityRepository $customFieldSetRepository */
        $customFieldSetRepository = $container->get('custom_field_set.repository');

        $customFieldSetNames = array_map(function ($customFieldSetArray) {
            return $customFieldSetArray["name"];
        }, $customFields);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter("name", $customFieldSetNames));
        $customFieldSetResult = $customFieldSetRepository->search($criteria, $context);

        if ($customFieldSetResult->getTotal() === 0) return;

        $customFieldSetsToRemove = $customFieldSetResult->map(
            function (CustomFieldSetEntity $customFieldSet) {
                return ["id" => $customFieldSet->getId()];
            }
        );

        $customFieldSetRepository->delete(array_values($customFieldSetsToRemove), $context);
    }
}
