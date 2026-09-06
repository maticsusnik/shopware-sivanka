<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Core\Content\ProductEnquiry;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\EmailField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use OptiwebProductEnquiry\Core\Content\ProductEnquiryLine\ProductEnquiryLineDefinition;

class ProductEnquiryDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'product_enquiry';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ProductEnquiryEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ProductEnquiryCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))->addFlags(new Required()),
            (new StringField('first_name', 'firstName'))->addFlags(new Required()),
            (new StringField('last_name', 'lastName'))->addFlags(new Required()),
            (new EmailField('email', 'email'))->addFlags(new Required()),
            new StringField('phone', 'phone'),
            // The first line is mirrored onto these columns so the admin list and
            // the historical rows keep working; `lines` is the real product set.
            new FkField('product_id', 'productId', ProductDefinition::class),
            (new StringField('product_name', 'productName'))->addFlags(new Required()),
            (new StringField('product_number', 'productNumber'))->addFlags(new Required()),
            new StringField('product_option', 'productOption'),
            (new IntField('quantity', 'quantity'))->addFlags(new Required()),
            (new IntField('product_count', 'productCount'))->addFlags(new Required()),
            new LongTextField('message', 'message'),
            (new StringField('status', 'status'))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),

            (new OneToManyAssociationField('lines', ProductEnquiryLineDefinition::class, 'product_enquiry_id'))
                ->addFlags(new CascadeDelete()),
        ]);
    }
}
