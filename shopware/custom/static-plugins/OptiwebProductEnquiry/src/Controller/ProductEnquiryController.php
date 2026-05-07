<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Controller;

use OptiwebProductEnquiry\Core\Content\ProductEnquiry\ProductEnquiryStatus;
use Shopware\Core\Content\Product\ProductEntity;
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
            file_put_contents('/tmp/enquiry_mail_error.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
        }

        $confirmationText = $this->trans('optiwebProductEnquiry.success.message');

        $slotId = $data->getString('slotId');
        if ($slotId) {
            $slotCriteria = new Criteria([$slotId]);
            $slot = $this->cmsSlotRepository->search($slotCriteria, $context->getContext())->first();
            if ($slot) {
                $config = $slot->getTranslated()['config'] ?? [];
                $text = $config['confirmationText']['value'] ?? '';
                if (!empty($text)) {
                    $confirmationText = $text;
                }
            }
        }

        return new JsonResponse([
            'type'   => 'success',
            'alerts' => [['type' => 'success', 'content' => $confirmationText]],
        ]);
    }

    private function sendAdminEmail(RequestDataBag $data, ProductEntity $product, SalesChannelContext $context): void
    {
        $log = fn(string $msg) => file_put_contents('/tmp/enquiry_mail_debug.log', date('H:i:s') . " $msg\n", FILE_APPEND);

        $typeCriteria = new Criteria();
        $typeCriteria->addFilter(new EqualsFilter('technicalName', 'product_enquiry_form'));
        $typeCriteria->setLimit(1);

        $type = $this->mailTemplateTypeRepository->search($typeCriteria, $context->getContext())->first();
        $log('type: ' . ($type ? $type->getId() : 'NULL'));
        if (!$type) {
            return;
        }

        $templateCriteria = new Criteria();
        $templateCriteria->addFilter(new EqualsFilter('mailTemplateTypeId', $type->getId()));
        $templateCriteria->setLimit(1);

        $template = $this->mailTemplateRepository->search($templateCriteria, $context->getContext())->first();
        $log('template: ' . ($template ? $template->getId() : 'NULL'));
        if (!$template) {
            return;
        }

        $adminEmail = $this->systemConfigService->getString(
            'OptiwebProductEnquiry.config.adminEmail',
            $context->getSalesChannelId()
        );

        if (empty($adminEmail)) {
            $adminEmail = $this->systemConfigService->getString('core.basicInformation.email');
        }

        $log('adminEmail: ' . $adminEmail);
        if (empty($adminEmail)) {
            return;
        }

        $recipients = [$adminEmail => $adminEmail];

        $log('sending to: ' . $adminEmail);
        try {
            $result = $this->mailService->send(
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
            $log('send result: ' . ($result ? 'ok' : 'null'));
        } catch (\Throwable $ex) {
            $log('send exception: ' . $ex->getMessage());
            $log($ex->getTraceAsString());
            throw $ex;
        }
    }
}
