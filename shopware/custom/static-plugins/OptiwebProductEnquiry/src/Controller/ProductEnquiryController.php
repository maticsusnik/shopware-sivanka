<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Controller;

use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Defaults;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ProductEnquiryController extends StorefrontController
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaService $mediaService,
        private readonly MailService $mailService,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly EntityRepository $mailTemplateTypeRepository,
        private readonly EntityRepository $logEntryRepository,
        private readonly EntityRepository $cmsSlotRepository,
        private readonly ValidatorInterface $validator
    ) {
    }

    #[Route(path: '/form/product-enquiry', name: 'frontend.form.product-enquiry.send', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function sendProductEnquiry(RequestDataBag $data, SalesChannelContext $context): JsonResponse
    {
        $response = [];
        $data->set('subject', $this->trans('optiwebProductEnquiry.subject'));

        // Validate required fields
        $violations = new ConstraintViolationList();
        
        $firstNameViolations = $this->validator->validate($data->get('firstName'), [
            new NotBlank(['message' => $this->trans('error.VIOLATION::FIRST_NAME_IS_BLANK_ERROR')])
        ]);
        $violations->addAll($firstNameViolations);

        $lastNameViolations = $this->validator->validate($data->get('lastName'), [
            new NotBlank(['message' => $this->trans('error.VIOLATION::LAST_NAME_IS_BLANK_ERROR')])
        ]);
        $violations->addAll($lastNameViolations);

        $emailViolations = $this->validator->validate($data->get('email'), [
            new NotBlank(['message' => $this->trans('error.VIOLATION::IS_BLANK_ERROR')]),
            new Email(['message' => $this->trans('error.VIOLATION::INVALID_EMAIL_FORMAT_ERROR')])
        ]);
        $violations->addAll($emailViolations);

        $qtyValue = $data->get('productQty');
        if (empty($qtyValue) || $qtyValue <= 0) {
            $qtyViolations = $this->validator->validate($qtyValue, [
                new NotBlank(['message' => $this->trans('error.VIOLATION::IS_BLANK_ERROR')])
            ]);
            $violations->addAll($qtyViolations);
        }

        if ($violations->count() > 0) {
            $violationMessages = [];
            foreach ($violations as $violation) {
                $violationMessages[] = $violation->getMessage();
            }

            $this->writeLogs('product_enquiry.validation_error', $violationMessages, $context->getContext(), 400);

            return new JsonResponse([
                [
                    'type' => 'danger',
                    'alert' => $this->renderView('@Storefront/storefront/utilities/alert.html.twig', [
                        'type' => 'danger',
                        'list' => $violationMessages,
                    ]),
                ]
            ]);
        }

        // Set default values for optional fields
        if (empty($data->get('phone'))) {
            $data->set('phone', '');
        }

        if (empty($data->get('comment'))) {
            $data->set('comment', '');
        }

        // Get product by product_id
        if ($data->get('product_id')) {
            $product = $this->getProductById($data->get('product_id'), $context->getContext());
            
            if ($product) {
                $data->set('product', $product);
            } else {
                $this->writeLogs('product_enquiry.missing_product', $data->all(), $context->getContext());
                
                return new JsonResponse([
                    [
                        'type' => 'danger',
                        'alert' => $this->trans('optiwebProductEnquiry.errors.productNotFound'),
                    ]
                ]);
            }
        } else {
            $this->writeLogs('product_enquiry.missing_product_id', $data->all(), $context->getContext());

            return new JsonResponse([
                [
                    'type' => 'danger',
                    'alert' => $this->trans('optiwebProductEnquiry.errors.missingProductId'),
                ]
            ]);
        }

        try {
            // Send mail to customer and add sales team to bcc
            $messageCustomer = $this->sendMailToCustomer($data, $context);
            $data->set('mailMessage', $messageCustomer);
            $this->writeLogs('product_enquiry.mail_sent', $data->all(), $context->getContext(), 200);

            $response[] = [
                'type' => 'success',
                'alert' => $messageCustomer,
            ];

        } catch (ConstraintViolationException $formViolations) {
            $violations = [];
            foreach ($formViolations->getViolations() as $violation) {
                $violations[] = $violation->getMessage();
            }

            $this->writeLogs('product_enquiry.mail_send_error', $violations, $context->getContext(), 400);

            $response[] = [
                'type' => 'danger',
                'alert' => $this->renderView('@Storefront/storefront/utilities/alert.html.twig', [
                    'type' => 'danger',
                    'list' => $violations,
                ]),
            ];
        } catch (\Exception $e) {
            $this->writeLogs('product_enquiry.unexpected_error', [
                'error' => $e->getMessage(),
                'data' => $data->all()
            ], $context->getContext(), 500);

            $response[] = [
                'type' => 'danger',
                'alert' => $this->trans('optiwebProductEnquiry.errors.unexpectedError'),
            ];
        }

        return new JsonResponse($response);
    }

    private function getProductById(string $productId, Context $context): ?object
    {
        $criteria = new Criteria();
        $criteria->addAssociation('cover');
        $criteria->addAssociation('media');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('manufacturer');
        $criteria->addFilter(new EqualsFilter('product.id', $productId));

        return $this->productRepository
            ->search($criteria, $context)
            ->getEntities()
            ->first();
    }

    private function sendMailToCustomer(RequestDataBag $contactFormData, SalesChannelContext $context): string
    {
        $salesChannel = $context->getSalesChannel();
        $salesChannelId = $context->getSalesChannel()->getId();

        // Get mail template type by technical name - try config first, then look up by technical name
        $mailTemplateTypeId = $this->systemConfigService->get('OptiwebProductEnquiry.config.mailTemplateType', $salesChannelId);
        
        if (empty($mailTemplateTypeId)) {
            // If not configured, try to find by technical name
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('technicalName', 'product_enquiry_form'));
            $mailTemplateType = $this->mailTemplateTypeRepository->search($criteria, $context->getContext())->first();
            
            if ($mailTemplateType === null) {
                $contactFormData->set('errorMessage', 'Missing mailTemplateType for product enquiry form.');
                $this->writeLogs('product_enquiry.mail_send_customer_error', $contactFormData->all(), $context->getContext(), 300);

                return $this->trans('optiwebProductEnquiry.errors.missingMailTemplate');
            }
            
            $mailTemplateTypeId = $mailTemplateType->getId();
        }

        // Get mail template by type
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateTypeId', $mailTemplateTypeId));
        $criteria->addAssociation('media.media');
        $criteria->setLimit(1);

        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context->getContext())->first();

        if ($mailTemplate === null) {
            $contactFormData->set('errorMessage', 'Missing mailTemplate for product enquiry form. Please create a mail template for type "product_enquiry_form".');
            $this->writeLogs('product_enquiry.mail_send_customer_error', $contactFormData->all(), $context->getContext(), 300);

            return $this->trans('optiwebProductEnquiry.errors.missingMailTemplate');
        }

        $receivers = [];
        $message = '';
        
        if ($contactFormData->has('slotId')) {
            $slotId = $contactFormData->get('slotId');

            if ($slotId) {
                $criteria = new Criteria([$slotId]);
                $slot = $this->cmsSlotRepository
                    ->search($criteria, $context->getContext())
                    ->getEntities()
                    ->first();

                if ($slot) {
                    $slotTranslated = $slot->get('translated');
                    $receivers = $slotTranslated['config']['mailReceiver']['value'] ?? [];
                    $message = $slotTranslated['config']['confirmationText']['value'] ?? '';
                }
            }
        }

        if (empty($message)) {
            $message = $this->trans('optiwebProductEnquiry.success.message');
        }
        
        if (empty($receivers)) {
            $defaultEmail = $this->systemConfigService->get('core.basicInformation.email', $salesChannelId);
            if ($defaultEmail) {
                $receivers[] = $defaultEmail;
            }
        }
        
        // Ensure receivers array is valid
        if (empty($receivers)) {
            $contactFormData->set('errorMessage', 'No email recipients configured.');
            $this->writeLogs('product_enquiry.mail_send_customer_error', $contactFormData->all(), $context->getContext(), 300);
            return $this->trans('optiwebProductEnquiry.errors.missingMailTemplate');
        }
        
        // Convert to associative array for MailRecipientStruct (key = value)
        $receiversAssoc = [];
        foreach ($receivers as $email) {
            $receiversAssoc[$email] = $email;
        }
        $receivers = $receiversAssoc;

        // Recipients
        $recipients = new MailRecipientStruct($receivers);

        $data = new DataBag();
        $data->set('recipients', $recipients->getRecipients());
        $data->set('senderName', $mailTemplate->getTranslation('senderName'));
        $data->set('salesChannelId', $salesChannelId);
        $data->set('templateId', $mailTemplate->getId());
        $data->set('customFields', $mailTemplate->getCustomFields());
        $data->set('contentHtml', $mailTemplate->getTranslation('contentHtml'));
        $data->set('contentPlain', $mailTemplate->getTranslation('contentPlain'));
        $data->set('subject', $mailTemplate->getTranslation('subject'));
        $data->set('mediaIds', []);

        $attachments = [];
        if ($mailTemplate->getMedia() !== null) {
            foreach ($mailTemplate->getMedia() as $mailTemplateMedia) {
                if ($mailTemplateMedia->getMedia() === null) {
                    continue;
                }
                if ($mailTemplateMedia->getLanguageId() !== null && $mailTemplateMedia->getLanguageId() !== $context->getContext()->getLanguageId()) {
                    continue;
                }

                $attachments[] = $this->mediaService->getAttachment(
                    $mailTemplateMedia->getMedia(),
                    $context->getContext()
                );
            }
        }
        
        if (!empty($attachments)) {
            $data->set('binAttachments', $attachments);
        }

        $mailServiceData = [
            'contactFormData' => $contactFormData->all(),
            'product' => $contactFormData->get('product'),
            'salesChannel' => $salesChannel,
        ];

        $this->mailService->send(
            $data->all(),
            $context->getContext(),
            $mailServiceData
        );

        return $message;
    }

    private function writeLogs(string $message, array $logData, Context $context, int $level = 100): void
    {
        $data = [
            'source' => 'storefront',
            'additionalData' => $logData
        ];

        $this->logEntryRepository->create(
            [
                [
                    'message' => $message,
                    'level' => $level,
                    'channel' => 'product_enquiry',
                    'context' => $data,
                    'createdAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            ],
            $context
        );
    }
}
