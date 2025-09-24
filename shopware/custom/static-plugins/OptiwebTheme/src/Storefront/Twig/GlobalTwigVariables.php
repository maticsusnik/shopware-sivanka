<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Twig;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class GlobalTwigVariables extends AbstractExtension implements GlobalsInterface
{

    private SystemConfigService $systemConfigService;

    public function __construct(
        private readonly RequestStack $requestStack,
        SystemConfigService $systemConfigService
    )
    {
        $this->systemConfigService = $systemConfigService;
    }

    public function getGlobals(): array
    {

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return [];
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$context instanceof SalesChannelContext) {
            return [];
        }

        $customer = $context->getCustomer();
        $b2bSc = $this->systemConfigService->get('OptiwebSync.config.customerB2BSubjectGroup') ?? null;
        $customerIsLoggedIn = $customer && !$customer->getGuest();
        $customFields = $customer ? $customer->getCustomFields(): [];

        return [
            'customerIsLoggedIn' => $customerIsLoggedIn,
        ];
    }

}
