<?php declare(strict_types=1);

namespace OptiwebTheme\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Flow\Indexing\FlowIndexer;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * A copy of every order confirmation to the shop, info@sivanka.com.
 *
 * Shopware never mails the "Shop owner's email address" on a new order: the stock
 * "Order placed" flow only mails the customer, and the flow's "admin" recipient means
 * the admin users, not that address. So the flow gets a second mail action with a
 * custom recipient — editable in Settings > Flow Builder like any other.
 *
 * Skipped when the flow already mails that address, so an action added by hand in
 * the admin is not doubled.
 */
class Migration1791800006SivankaOrderOwnerNotification extends MigrationStep
{
    private const OWNER_EMAIL = 'info@sivanka.com';
    private const OWNER_NAME = 'Sivanka';

    public function getCreationTimestamp(): int
    {
        return 1791800006;
    }

    public function update(Connection $connection): void
    {
        $flowId = $connection->fetchOne(
            "SELECT id FROM flow WHERE event_name = 'checkout.order.placed' AND active = 1 ORDER BY priority DESC, created_at ASC LIMIT 1"
        );
        if ($flowId === false) {
            return;
        }

        $sequences = $connection->fetchAllAssociative(
            "SELECT parent_id, rule_id, true_case, display_group, position, config
             FROM flow_sequence WHERE flow_id = :flowId AND action_name = 'action.mail.send'",
            ['flowId' => $flowId]
        );

        $customerMail = null;
        foreach ($sequences as $sequence) {
            if (str_contains((string) $sequence['config'], self::OWNER_EMAIL)) {
                return;
            }

            $config = json_decode((string) $sequence['config'], true);
            if (($config['recipient']['type'] ?? null) === 'default') {
                $customerMail ??= ['sequence' => $sequence, 'config' => $config];
            }
        }

        if ($customerMail === null) {
            return;
        }

        $config = $customerMail['config'];
        $config['recipient'] = [
            'type' => 'custom',
            'data' => [self::OWNER_EMAIL => self::OWNER_NAME],
        ];

        // Next to the customer mail, under the same condition (if any).
        $sequence = $customerMail['sequence'];
        $connection->insert('flow_sequence', [
            'id'            => Uuid::randomBytes(),
            'flow_id'       => $flowId,
            'parent_id'     => $sequence['parent_id'],
            'rule_id'       => $sequence['rule_id'],
            'action_name'   => 'action.mail.send',
            'config'        => json_encode($config, \JSON_THROW_ON_ERROR),
            'position'      => (int) $sequence['position'] + 1,
            'display_group' => (int) $sequence['display_group'],
            'true_case'     => (int) $sequence['true_case'],
            'created_at'    => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        // The flow runs from its serialized payload, rebuilt by the indexer.
        $this->registerIndexer($connection, FlowIndexer::NAME);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
