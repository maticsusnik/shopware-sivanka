<?php declare(strict_types=1);

namespace OptiwebSync;

use OptiwebSync\Helper\GlobalVariables;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class OptiwebSync extends Plugin
{
    /** Fixed ids so the set is upserted rather than duplicated. */
    private const CUSTOM_FIELD_SET_ID = '0f9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c';
    private const CUSTOM_FIELD_RELATION_ID = 'd1b2c3d4e5f60718293a4b5c6d7e8f93';

    public function install(InstallContext $installContext): void
    {
        $this->upsertOrderCustomFields($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->upsertOrderCustomFields($updateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->upsertOrderCustomFields($activateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $repository = $this->customFieldSetRepository();
        if ($repository === null) {
            return;
        }

        $repository->delete([['id' => self::CUSTOM_FIELD_SET_ID]], $uninstallContext->getContext());
    }

    /**
     * Register the order export bookkeeping fields as a real custom field set.
     *
     * The sync writes these keys either way, but without a registered set they
     * are invisible in the Administration — so nobody can see that an order
     * failed to export, or why. Upserted on install, update and activate so an
     * already-installed plugin picks them up too.
     */
    private function upsertOrderCustomFields(Context $context): void
    {
        $repository = $this->customFieldSetRepository();
        if ($repository === null) {
            return;
        }

        $repository->upsert([[
            'id'       => self::CUSTOM_FIELD_SET_ID,
            'name'     => GlobalVariables::CUSTOM_FIELD_SET,
            'global'   => false,
            'config'   => [
                'label' => [
                    'en-GB' => 'Minimax sync',
                    'de-DE' => 'Minimax-Sync',
                ],
                'translated' => true,
            ],
            'customFields' => [
                $this->field(
                    'a1b2c3d4e5f60718293a4b5c6d7e8f90',
                    GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS,
                    'Minimax export status',
                    1,
                ),
                $this->field(
                    'b1b2c3d4e5f60718293a4b5c6d7e8f91',
                    GlobalVariables::CUSTOM_FIELD_OPTIWEB_KEY,
                    'Minimax order id',
                    2,
                ),
                $this->field(
                    'c1b2c3d4e5f60718293a4b5c6d7e8f92',
                    GlobalVariables::CUSTOM_FIELD_OPTIWEB_ERROR,
                    'Minimax export error',
                    3,
                ),
            ],
            'relations' => [[
                'id'         => self::CUSTOM_FIELD_RELATION_ID,
                'entityName' => 'order',
            ]],
        ]], $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $id, string $name, string $label, int $position): array
    {
        return [
            'id'     => $id,
            'name'   => $name,
            'type'   => 'text',
            'config' => [
                'label'             => ['en-GB' => $label],
                'componentName'     => 'sw-field',
                'customFieldType'   => 'text',
                'customFieldPosition' => $position,
                // Written by the sync, not by hand.
                'disabled'          => true,
            ],
        ];
    }

    private function customFieldSetRepository(): ?EntityRepository
    {
        // Typed property with no default: it may not be initialised at all when
        // the plugin lifecycle runs outside a booted kernel.
        if (!isset($this->container) || !$this->container->has('custom_field_set.repository')) {
            return null;
        }

        $repository = $this->container->get('custom_field_set.repository');

        return $repository instanceof EntityRepository ? $repository : null;
    }
}
