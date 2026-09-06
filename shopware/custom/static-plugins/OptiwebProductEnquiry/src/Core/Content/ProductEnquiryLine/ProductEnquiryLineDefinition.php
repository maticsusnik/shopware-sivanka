<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Core\Content\ProductEnquiryLine;

use OptiwebProductEnquiry\Core\Content\ProductEnquiry\ProductEnquiryDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductEnquiryLineDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'product_enquiry_line';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ProductEnquiryLineEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ProductEnquiryLineCollection::class;
    }

    public function getParentDefinitionClass(): ?string
    {
        return ProductEnquiryDefinition::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('product_enquiry_id', 'productEnquiryId', ProductEnquiryDefinition::class))->addFlags(new Required()),
            // Nullable on purpose: the enquiry has to survive the product being
            // deleted, which is why name/number are copied onto the line.
            new FkField('product_id', 'productId', ProductDefinition::class),
            (new StringField('product_name', 'productName'))->addFlags(new Required()),
            (new StringField('product_number', 'productNumber'))->addFlags(new Required()),
            new StringField('product_option', 'productOption'),
            (new IntField('quantity', 'quantity'))->addFlags(new Required()),
            (new IntField('position', 'position'))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),

            new ManyToOneAssociationField('productEnquiry', 'product_enquiry_id', ProductEnquiryDefinition::class, 'id', false),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id', false),
        ]);
    }
}
