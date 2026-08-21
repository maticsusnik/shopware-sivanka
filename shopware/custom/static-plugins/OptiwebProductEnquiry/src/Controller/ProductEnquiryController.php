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
                'productQty' => [new Assert\NotBlank(), new Assert\Positive()],
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

        $productId = $data->getString('product_id');
        if (empty($productId)) {
            return new JsonResponse([
                'type'   => 'danger',
                'alerts' => [['type' => 'danger', 'content' => $this->trans('optiwebProductEnquiry.errors.missingProductId')]],
            ]);
        }

        $productCriteria = new Criteria([$productId]);
        $productCriteria->addAssociations(['cover', 'cover.media', 'options', 'options.group']);

        $product = $this->productRepository->search(
            $productCriteria,
            $context->getContext()
        )->first();

        if (!$product) {
            return new JsonResponse([
                'type'   => 'danger',
                'alerts' => [['type' => 'danger', 'content' => $this->trans('optiwebProductEnquiry.errors.productNotFound')]],
            ]);
        }

        // A double click or a re-fired submit would otherwise file the same enquiry twice.
        if ($this->findRecentDuplicate($data, $productId, $context)) {
            $this->logger->info('OptiwebProductEnquiry: swallowed a duplicate enquiry submission', [
                'email'     => $data->getString('email'),
                'productId' => $productId,
            ]);

            return new JsonResponse([
                'type'   => 'success',
                'alerts' => [['type' => 'success', 'content' => $this->resolveConfirmationText($data, $context)]],
            ]);
        }

        $this->productEnquiryRepository->create([[
            'id'             => Uuid::randomHex(),
            'salesChannelId' => $context->getSalesChannelId(),
            'firstName'      => $data->getString('firstName'),
            'lastName'       => $data->getString('lastName'),
            'email'          => $data->getString('email'),
            'phone'          => $data->getString('phone') ?: null,
            'productId'      => $productId,
            'productName'    => $product->getTranslated()['name'] ?? '',
            'productNumber'  => $product->getProductNumber(),
            'productOption'  => $data->getString('product_option') ?: null,
            'quantity'       => (int) $data->get('productQty', 1),
            'message'        => $data->getString('comment') ?: null,
            'status'         => ProductEnquiryStatus::New->value,
        ]], $context->getContext());

        try {
            $this->sendAdminEmail($data, $product, $context);
        } catch (\Throwable $e) {
            // The enquiry itself is already stored, so a mail failure must not fail the request.
            $this->logger->error('OptiwebProductEnquiry: notification mail failed', [
                'exception' => $e,
                'productId' => $productId,
            ]);
        }

        return new JsonResponse([
            'type'   => 'success',
            'alerts' => [['type' => 'success', 'content' => $this->resolveConfirmationText($data, $context)]],
        ]);
    }

    /**
     * Returns true when an identical enquiry (same e-mail, product and quantity) was
     * filed in the last minute, so accidental double submits do not create two rows.
     */
    private function findRecentDuplicate(RequestDataBag $data, string $productId, SalesChannelContext $context): bool
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('email', $data->getString('email')));
            $criteria->addFilter(new EqualsFilter('productId', $productId));
            $criteria->addFilter(new EqualsFilter('quantity', (int) $data->get('productQty', 1)));
            $criteria->addFilter(new RangeFilter('createdAt', [
                RangeFilter::GTE => (new \DateTimeImmutable('-60 seconds'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]));
            $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
            $criteria->setLimit(1);

            return $this->productEnquiryRepository->search($criteria, $context->getContext())->getTotal() > 0;
        } catch (\Throwable $e) {
            // A failed check must never block a legitimate enquiry.
            $this->logger->warning('OptiwebProductEnquiry: duplicate check failed', ['exception' => $e]);

            return false;
        }
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

    private function sendAdminEmail(RequestDataBag $data, ProductEntity $product, SalesChannelContext $context): void
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
                'product'         => $product,
                'salesChannel'    => $context->getSalesChannel(),
            ]
        );
    }
}
