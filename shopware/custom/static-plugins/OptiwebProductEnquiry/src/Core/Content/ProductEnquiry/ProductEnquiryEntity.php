<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Core\Content\ProductEnquiry;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ProductEnquiryEntity extends Entity
{
    use EntityIdTrait;

    protected string $salesChannelId;
    protected string $firstName;
    protected string $lastName;
    protected string $email;
    protected ?string $phone = null;
    protected ?string $productId = null;
    protected string $productName;
    protected string $productNumber;
    protected ?string $productOption = null;
    protected int $quantity;
    protected ?string $message = null;
    protected ProductEnquiryStatus $status;

    public function getSalesChannelId(): string { return $this->salesChannelId; }
    public function setSalesChannelId(string $salesChannelId): void { $this->salesChannelId = $salesChannelId; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): void { $this->phone = $phone; }

    public function getProductId(): ?string { return $this->productId; }
    public function setProductId(?string $productId): void { $this->productId = $productId; }

    public function getProductName(): string { return $this->productName; }
    public function setProductName(string $productName): void { $this->productName = $productName; }

    public function getProductNumber(): string { return $this->productNumber; }
    public function setProductNumber(string $productNumber): void { $this->productNumber = $productNumber; }

    public function getProductOption(): ?string { return $this->productOption; }
    public function setProductOption(?string $productOption): void { $this->productOption = $productOption; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): void { $this->quantity = $quantity; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): void { $this->message = $message; }

    public function getStatus(): ProductEnquiryStatus { return $this->status; }

    public function setStatus(string|ProductEnquiryStatus $status): void
    {
        $this->status = is_string($status) ? ProductEnquiryStatus::from($status) : $status;
    }
}
