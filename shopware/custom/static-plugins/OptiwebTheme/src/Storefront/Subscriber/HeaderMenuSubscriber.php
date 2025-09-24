<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Pagelet\Header\HeaderPageletLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Content\Category\Service\NavigationLoaderInterface;

class HeaderMenuSubscriber implements EventSubscriberInterface
{

    public function __construct(
        private NavigationLoaderInterface $navigationLoader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            HeaderPageletLoadedEvent::class => 'onNavigationPageLoaded',
        ];
    }

    public function onNavigationPageLoaded(HeaderPageletLoadedEvent $headerPageletLoadedEvent): void
    {
        $this->addHeaderServiceMenu($headerPageletLoadedEvent);

        $navigationCategoryId = $headerPageletLoadedEvent->getSalesChannelContext()->getSalesChannel()->getNavigationCategoryId();
        $headerPageletLoadedEvent->getPagelet()->addExtension("navigationCategory", new ArrayStruct(["id" => $navigationCategoryId]));
    }

    private function addHeaderServiceMenu(HeaderPageletLoadedEvent $headerPageletLoadedEvent): void
    {

        try {
            $categoryId = $headerPageletLoadedEvent->getSalesChannelContext()->getSalesChannel()->getServiceCategoryId();
            if (is_null($categoryId)) {
                throw new \LogicException('Missing or invalid config');
            }

            $salesChannel = $headerPageletLoadedEvent->getSalesChannelContext()->getSalesChannel();

            $navigation = $this->navigationLoader->load(
                $categoryId,
                $headerPageletLoadedEvent->getSalesChannelContext(),
                $categoryId,
                $salesChannel->getNavigationCategoryDepth()
            );

            $headerPageletLoadedEvent->getPagelet()->addExtension("headerServiceCategories", new ArrayStruct(["items" => $navigation]));
        } catch (\LogicException $ex) {
            $headerPageletLoadedEvent->getPagelet()->addExtension("headerServiceCategories", new ArrayStruct(["items" => null]));
        }
    }

}
