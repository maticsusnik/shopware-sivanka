<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Core\Content\ProductEnquiryLine;

use OptiwebProductEnquiry\Core\Content\ProductEnquiry\ProductEnquiryEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ProductEnquiryLineEntity extends Entity
{
    use EntityIdTrait;

    protected string $productEnquiryId;
    protected ?string $productId = null;
    protected string $productName;
    protected string $productNumber;
    protected ?string $productOption = null;
    protected int $quantity = 1;
    protected int $position = 0;
    protected ?ProductEnquiryEntity $productEnquiry = null;
    protected ?ProductEntity $product = null;

    public function getProductEnquiryId(): string
    {
        return $this->productEnquiryId;
    }

    public function setProductEnquiryId(string $productEnquiryId): void
    {
        $this->productEnquiryId = $productEnquiryId;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function setProductId(?string $productId): void
    {
        $this->productId = $productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): void
    {
        $this->productName = $productName;
    }

    public function getProductNumber(): string
    {
        return $this->productNumber;
    }

    public function setProductNumber(string $productNumber): void
    {
        $this->productNumber = $productNumber;
    }

    public function getProductOption(): ?string
    {
        return $this->productOption;
    }

    public function setProductOption(?string $productOption): void
    {
        $this->productOption = $productOption;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getProductEnquiry(): ?ProductEnquiryEntity
    {
        return $this->productEnquiry;
    }

    public function setProductEnquiry(?ProductEnquiryEntity $productEnquiry): void
    {
        $this->productEnquiry = $productEnquiry;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }
}
