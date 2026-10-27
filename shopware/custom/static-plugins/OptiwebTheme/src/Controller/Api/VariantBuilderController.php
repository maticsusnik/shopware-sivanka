<?php declare(strict_types=1);

namespace OptiwebTheme\Controller\Api;

use OptiwebTheme\Core\Content\Product\VariantBuilder\VariantBuilder;
use OptiwebTheme\Core\Content\Product\VariantBuilder\VariantSplitter;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the "Make variants" and "Split variants" dialogs in the product list.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class VariantBuilderController
{
    public function __construct(
        private readonly VariantBuilder $variantBuilder,
        private readonly VariantSplitter $variantSplitter,
    ) {
    }

    #[Route(
        path: '/api/_action/optiweb/variant-builder/preview',
        name: 'api.action.optiweb.variant-builder.preview',
        defaults: ['_acl' => ['product:read']],
        methods: ['POST'],
    )]
    public function preview(Request $request, Context $context): JsonResponse
    {
        return new JsonResponse($this->variantBuilder->preview(
            $this->ids($request, 'productIds'),
            $this->ids($request, 'groupIds'),
            $this->nullableString($request, 'parentId'),
            $context,
        ));
    }

    #[Route(
        path: '/api/_action/optiweb/variant-builder/create',
        name: 'api.action.optiweb.variant-builder.create',
        defaults: ['_acl' => ['product:create', 'product:update']],
        methods: ['POST'],
    )]
    public function create(Request $request, Context $context): JsonResponse
    {
        return new JsonResponse($this->variantBuilder->create(
            $this->ids($request, 'productIds'),
            $this->ids($request, 'groupIds'),
            $this->nullableString($request, 'parentId'),
            $this->nullableString($request, 'parentNumber'),
            $this->nullableString($request, 'parentName'),
            (bool) $request->request->get('inheritData', false),
            $context,
        ));
    }

    #[Route(
        path: '/api/_action/optiweb/variant-builder/split',
        name: 'api.action.optiweb.variant-builder.split',
        defaults: ['_acl' => ['product:update', 'product:delete']],
        methods: ['POST'],
    )]
    public function split(Request $request, Context $context): JsonResponse
    {
        return new JsonResponse($this->variantSplitter->split(
            $this->ids($request, 'parentIds'),
            (bool) $request->request->get('deleteParent', true),
            $context,
        ));
    }

    /**
     * @return array<int, string>
     */
    private function ids(Request $request, string $key): array
    {
        $value = $request->request->all()[$key] ?? [];

        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function nullableString(Request $request, string $key): ?string
    {
        $value = $request->request->get($key);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
