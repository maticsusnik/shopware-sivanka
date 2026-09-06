<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Controller;

use OptiwebProductEnquiry\Core\Content\ProductEnquiry\ProductEnquiryStatus;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class ProductEnquiryController extends StorefrontController
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly AbstractMailService $mailService,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly EntityRepository $mailTemplateTypeRepository,
        private readonly EntityRepository $productEnquiryRepository,
        private readonly EntityRepository $cmsSlotRepository,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/form/product-enquiry',
        name: 'frontend.form.product-enquiry.send',
        defaults: ['XmlHttpRequest' => true],
        options: ['seo' => false],
        methods: ['POST']
    )]
    public function sendProductEnquiry(RequestDataBag $data, SalesChannelContext $context): JsonResponse
    {
        $constraints = new Assert\Collection(
            fields: [
                'firstName'  => [new Assert\NotBlank()],
                'lastName'   => [new Assert\NotBlank()],
                'email'      => [new Assert\NotBlank(), new Assert\Email()],
            ],
            allowExtraFields: true,
        );

        $violations = $this->validator->validate($data->all(), $constraints);

        if (count($violations) > 0) {
            $fieldErrors = [];
            foreach ($violations as $violation) {
                $field = trim((string) $violation->getPropertyPath(), '[]');
                $fieldErrors[$field] = $violation->getMessage();
            }

            return new JsonResponse([
                'type'        => 'danger',
                'alerts'      => [['type' => 'danger', 'content' => $this->trans('optiwebProductEnquiry.errors.checkErrors')]],
                'fieldErrors' => $fieldErrors,
            ]);
        }

        $requested = $this->readRequestedProducts($data);

        if ($requested === []) {
            return new JsonResponse([
                'type'   => 'danger',
                'alerts' => [['type' => 'danger', 'content' => $this->trans('optiwebProductEnquiry.errors.missingProductId')]],
            ]);
        }

        $productCriteria = new Criteria(array_keys($requested));
        $productCriteria->addAssociations(['cover', 'cover.media', 'options', 'options.group']);

        $products = $this->productRepository->search($productCriteria, $context->getContext())->getEntities();

        if ($products->count() === 0) {
            return new JsonResponse([
                'type'   => 'danger',
                'alerts' => [['type' => 'danger', 'content' => $this->trans('optiwebProductEnquiry.errors.productNotFound')]],
            ]);
        }

        // Build the lines in the order they were submitted, skipping ids that no
        // longer resolve — a stale wishlist entry must not sink the whole enquiry.
        $lines    = [];
        $entities = [];
        $position = 0;

        foreach ($requested as $productId => $line) {
            $product = $products->get($productId);
            if ($product === null) {
                $this->logger->info('OptiwebProductEnquiry: dropped an unknown product from an enquiry', [
                    'productId' => $productId,
                ]);

                continue;
            }

            $entities[] = $product;
            $lines[]    = [
                'id'            => Uuid::randomHex(),
                'productId'     => $productId,
                'productName'   => $product->getTranslated()['name'] ?? '',
                'productNumber' => $product->getProductNumber(),
                'productOption' => $line['option'] ?: null,
                'quantity'      => $line['quantity'],
                'position'      => $position++,
            ];
        }

        if ($lines === []) {
            return new JsonResponse([
                'type'   => 'danger',
                'alerts' => [['type' => 'danger', 'content' => $this->trans('optiwebProductEnquiry.errors.productNotFound')]],
            ]);
        }

        // A double click or a re-fired submit would otherwise file the same enquiry twice.
        if ($this->findRecentDuplicate($data, $lines, $context)) {
            $this->logger->info('OptiwebProductEnquiry: swallowed a duplicate enquiry submission', [
                'email'    => $data->getString('email'),
                'products' => array_column($lines, 'productNumber'),
            ]);

            return new JsonResponse([
                'type'   => 'success',
                'alerts' => [['type' => 'success', 'content' => $this->resolveConfirmationText($data, $context)]],
            ]);
        }

        $first = $lines[0];

        $this->productEnquiryRepository->create([[
            'id'             => Uuid::randomHex(),
            'salesChannelId' => $context->getSalesChannelId(),
            'firstName'      => $data->getString('firstName'),
            'lastName'       => $data->getString('lastName'),
            'email'          => $data->getString('email'),
            'phone'          => $data->getString('phone') ?: null,
            // Mirrored from the first line: the admin list and older reports read
            // these columns and predate multi-product enquiries.
            'productId'      => $first['productId'],
            'productName'    => $first['productName'],
            'productNumber'  => $first['productNumber'],
            'productOption'  => $first['productOption'],
            'quantity'       => $first['quantity'],
            'productCount'   => count($lines),
            'message'        => $data->getString('comment') ?: null,
            'status'         => ProductEnquiryStatus::New->value,
            'lines'          => $lines,
        ]], $context->getContext());

        try {
            $this->sendAdminEmail($data, $entities, $lines, $context);
        } catch (\Throwable $e) {
            // The enquiry itself is already stored, so a mail failure must not fail the request.
            $this->logger->error('OptiwebProductEnquiry: notification mail failed', [
                'exception' => $e,
                'products'  => array_column($lines, 'productNumber'),
            ]);
        }

        return new JsonResponse([
            'type'   => 'success',
            'alerts' => [['type' => 'success', 'content' => $this->resolveConfirmationText($data, $context)]],
        ]);
    }

    /**
     * Read the requested products, from either shape the form can submit.
     *
     * Single product (product detail modal):
     *   product_id=<id>, productQty=<n>, product_option=<text>
     * Several products (wishlist modal):
     *   products[<id>][qty]=<n>, products[<id>][option]=<text>
     *
     * Keyed by product id, so the same product listed twice collapses into one
     * line instead of producing two rows for the same thing.
     *
     * @return array<string, array{quantity: int, option: string}>
     */
    private function readRequestedProducts(RequestDataBag $data): array
    {
        $requested = [];

        $products = $data->get('products');
        if ($products instanceof RequestDataBag) {
            $products = $products->all();
        }

        if (is_array($products)) {
            foreach ($products as $productId => $line) {
                if (!is_string($productId) || !Uuid::isValid($productId)) {
                    continue;
                }

                $line = is_array($line) ? $line : [];

                $requested[$productId] = [
                    'quantity' => $this->normaliseQuantity($line['qty'] ?? 1),
                    'option'   => trim((string) ($line['option'] ?? '')),
                ];
            }
        }

        if ($requested !== []) {
            return $requested;
        }

        $productId = $data->getString('product_id');
        if ($productId === '' || !Uuid::isValid($productId)) {
            return [];
        }

        return [$productId => [
            'quantity' => $this->normaliseQuantity($data->get('productQty', 1)),
            'option'   => trim($data->getString('product_option')),
        ]];
    }

    /** Clamped so a hand-edited form cannot store a zero, negative or absurd quantity. */
    private function normaliseQuantity(mixed $value): int
    {
        $quantity = (int) $value;

        return max(1, min($quantity, 9999));
    }

    /**
     * Returns true when the same person filed the same basket of products in the
     * last minute, so accidental double submits do not create two enquiries.
     *
     * Matching is done on the whole product set, not just the first line: two
     * wishlist enquiries a minute apart are only a duplicate if they cover
     * exactly the same products at the same quantities.
     *
     * @param list<array{productId: string, quantity: int}> $lines
     */
    private function findRecentDuplicate(RequestDataBag $data, array $lines, SalesChannelContext $context): bool
    {
        try {
            $criteria = new Criteria();
            $criteria->addAssociation('lines');
            $criteria->addFilter(new EqualsFilter('email', $data->getString('email')));
            $criteria->addFilter(new EqualsFilter('productCount', count($lines)));
            $criteria->addFilter(new RangeFilter('createdAt', [
                RangeFilter::GTE => (new \DateTimeImmutable('-60 seconds'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]));
            $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
            $criteria->setLimit(5);

            $fingerprint = $this->fingerprint($lines);

            foreach ($this->productEnquiryRepository->search($criteria, $context->getContext())->getEntities() as $enquiry) {
                $existing = [];
                foreach ($enquiry->getLines() ?? [] as $line) {
                    $existing[] = [
                        'productId' => $line->getProductId() ?? '',
                        'quantity'  => $line->getQuantity(),
                    ];
                }

                if ($this->fingerprint($existing) === $fingerprint) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            // A failed check must never block a legitimate enquiry.
            $this->logger->warning('OptiwebProductEnquiry: duplicate check failed', ['exception' => $e]);

            return false;
        }
    }

    /**
     * Order-independent signature of a product set.
     *
     * @param list<array{productId: string, quantity: int}> $lines
     */
    private function fingerprint(array $lines): string
    {
        $parts = array_map(
            static fn (array $line): string => $line['productId'] . ':' . $line['quantity'],
            $lines,
        );

        sort($parts);

        return implode('|', $parts);
    }

    private function resolveConfirmationText(RequestDataBag $data, SalesChannelContext $context): string
    {
        $confirmationText = $this->trans('optiwebProductEnquiry.success.message');

        $slotId = $data->getString('slotId');
        if ($slotId) {
            $slot = $this->cmsSlotRepository->search(new Criteria([$slotId]), $context->getContext())->first();
            if ($slot) {
                $config = $slot->getTranslated()['config'] ?? [];
                $text = $config['confirmationText']['value'] ?? '';
                if (!empty($text)) {
                    $confirmationText = $text;
                }
            }
        }

        return $confirmationText;
    }

    /**
     * @param list<ProductEntity>                                                                           $products
     * @param list<array{productName: string, productNumber: string, productOption: ?string, quantity: int}> $lines
     */
    private function sendAdminEmail(RequestDataBag $data, array $products, array $lines, SalesChannelContext $context): void
    {
        $typeCriteria = new Criteria();
        $typeCriteria->addFilter(new EqualsFilter('technicalName', 'product_enquiry_form'));
        $typeCriteria->setLimit(1);

        $type = $this->mailTemplateTypeRepository->search($typeCriteria, $context->getContext())->first();
        if (!$type) {
            $this->logger->warning('OptiwebProductEnquiry: mail template type "product_enquiry_form" is missing');

            return;
        }

        $templateCriteria = new Criteria();
        $templateCriteria->addFilter(new EqualsFilter('mailTemplateTypeId', $type->getId()));
        $templateCriteria->setLimit(1);

        $template = $this->mailTemplateRepository->search($templateCriteria, $context->getContext())->first();
        if (!$template) {
            $this->logger->warning('OptiwebProductEnquiry: no mail template for type "product_enquiry_form"');

            return;
        }

        $adminEmail = $this->systemConfigService->getString(
            'OptiwebProductEnquiry.config.adminEmail',
            $context->getSalesChannelId()
        );

        if (empty($adminEmail)) {
            $adminEmail = $this->systemConfigService->getString('core.basicInformation.email');
        }

        if (empty($adminEmail)) {
            $this->logger->warning('OptiwebProductEnquiry: no recipient configured for enquiry notifications');

            return;
        }

        $recipients = [$adminEmail => $adminEmail];

        $this->mailService->send(
            [
                'recipients'     => $recipients,
                'senderName'     => $template->getTranslated()['senderName'] ?? '{{ salesChannel.name }}',
                'salesChannelId' => $context->getSalesChannelId(),
                'templateId'     => $template->getId(),
                'contentHtml'    => $template->getTranslated()['contentHtml'] ?? '',
                'contentPlain'   => $template->getTranslated()['contentPlain'] ?? '',
                'subject'        => $template->getTranslated()['subject'] ?? 'Product Enquiry',
            ],
            $context->getContext(),
            [
                'contactFormData' => $data->all(),
                // `product` stays for templates written before multi-product
                // enquiries existed; `enquiryLines` is the full set.
                'product'         => $products[0] ?? null,
                'products'        => $products,
                'enquiryLines'    => $lines,
                'salesChannel'    => $context->getSalesChannel(),
            ]
        );
    }
}
