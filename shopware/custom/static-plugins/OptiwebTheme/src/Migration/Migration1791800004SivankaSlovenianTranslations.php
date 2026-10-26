<?php declare(strict_types=1);

namespace OptiwebTheme\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Slovenian names for the core records the shop shows to customers.
 *
 * The shop's only language is Shopware's system language renamed to "Slovenščina",
 * so salutations, payment and shipping methods, delivery times, the currency, order /
 * payment / shipment states and all country names still carried the English install
 * values: "Cash on delivery", "Not specified", "Slovenia", "Done" in the account.
 *
 * A row is only replaced while it still holds the English value (or, for countries,
 * was never edited), so anything changed in the admin is kept.
 */
class Migration1791800004SivankaSlovenianTranslations extends MigrationStep
{
    /** salutation_key => [stock display name, display name, letter name] */
    private const SALUTATIONS = [
        'mr'            => ['Mr.', 'Gospod', 'Spoštovani gospod'],
        'mrs'           => ['Mrs.', 'Gospa', 'Spoštovana gospa'],
        'not_specified' => ['Not specified', 'Ni navedeno', 'Pozdravljeni'],
    ];

    /** technical_name => [stock name, name, description] */
    private const PAYMENT_METHODS = [
        'payment_cashpayment'    => ['Cash on delivery', 'Plačilo po povzetju', 'Plačilo ob prevzemu pošiljke.'],
        'payment_invoicepayment' => ['Invoice', 'Plačilo po računu', 'Plačilo na podlagi računa po prejemu blaga.'],
        'payment_prepayment'     => ['Paid in advance', 'Predplačilo', 'Naročilo plačate vnaprej na naš transakcijski račun, pošiljko odpošljemo po prejemu plačila.'],
        'payment_debitpayment'   => ['Direct Debit', 'Direktna obremenitev', 'Znesek bremenimo neposredno z vašega bančnega računa.'],
    ];

    /** technical_name => [stock name, name] */
    private const SHIPPING_METHODS = [
        'shipping_standard' => ['Standard', 'Standardna dostava'],
        'shipping_express'  => ['Express', 'Hitra dostava'],
    ];

    /** stock name => name */
    private const DELIVERY_TIMES = [
        '1-3 days'         => '1–3 dni',
        '2-5 days'         => '2–5 dni',
        '1-2 weeks'        => '1–2 tedna',
        '3-4 weeks'        => '3–4 tedne',
        'Instant download' => 'Takojšen prenos',
    ];

    /** state machine => [state => name] */
    private const STATES = [
        'order.state' => [
            'open'        => 'Odprto',
            'in_progress' => 'V obdelavi',
            'completed'   => 'Zaključeno',
            'cancelled'   => 'Preklicano',
        ],
        'order_delivery.state' => [
            'open'               => 'Ni še poslano',
            'shipped'            => 'Poslano',
            'shipped_partially'  => 'Delno poslano',
            'returned'           => 'Vrnjeno',
            'returned_partially' => 'Delno vrnjeno',
            'cancelled'          => 'Preklicano',
        ],
        'order_transaction.state' => [
            'open'               => 'Čaka na plačilo',
            'in_progress'        => 'V obdelavi',
            'unconfirmed'        => 'Nepotrjeno',
            'authorized'         => 'Odobreno',
            'paid'               => 'Plačano',
            'paid_partially'     => 'Delno plačano',
            'reminded'           => 'Poslan opomin',
            'failed'             => 'Neuspešno',
            'cancelled'          => 'Preklicano',
            'refunded'           => 'Vrnjeno',
            'refunded_partially' => 'Delno vrnjeno',
            'chargeback'         => 'Bremenitev vrnjena',
        ],
        'order_transaction_capture.state' => [
            'pending'   => 'V teku',
            'completed' => 'Zaključeno',
            'failed'    => 'Neuspešno',
        ],
        'order_transaction_capture_refund.state' => [
            'open'        => 'Odprto',
            'in_progress' => 'V obdelavi',
            'completed'   => 'Zaključeno',
            'failed'      => 'Neuspešno',
            'cancelled'   => 'Preklicano',
        ],
    ];

    /** English install names of the state machine states, so an edited name is kept */
    private const STOCK_STATE_NAMES = [
        'Open', 'In Progress', 'Done', 'Complete', 'Completed', 'Cancelled', 'Shipped', 'Shipped (partially)',
        'Returned', 'Returned (partially)', 'Unconfirmed', 'Authorized', 'Paid', 'Paid (partially)', 'Reminded',
        'Failed', 'Refunded', 'Refunded (partially)', 'Chargeback', 'Pending',
    ];

    /** ICU's Slovenian names that are too long for an address line */
    private const COUNTRY_NAME_OVERRIDES = [
        'HK' => 'Hongkong',
        'MO' => 'Macao',
    ];

    public function getCreationTimestamp(): int
    {
        return 1791800004;
    }

    public function update(Connection $connection): void
    {
        $languageIds = $connection->fetchFirstColumn(
            "SELECT l.id FROM language l JOIN locale lo ON lo.id = l.locale_id WHERE lo.code LIKE 'sl%'"
        );

        foreach ($languageIds as $languageId) {
            $this->salutations($connection, $languageId);
            $this->paymentMethods($connection, $languageId);
            $this->shippingMethods($connection, $languageId);
            $this->deliveryTimes($connection, $languageId);
            $this->states($connection, $languageId);
            $this->currency($connection, $languageId);
            $this->countries($connection, $languageId);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function salutations(Connection $connection, string $languageId): void
    {
        foreach (self::SALUTATIONS as $key => [$stock, $displayName, $letterName]) {
            $connection->executeStatement(
                'UPDATE salutation_translation t JOIN salutation s ON s.id = t.salutation_id
                 SET t.display_name = :displayName, t.letter_name = :letterName, t.updated_at = :now
                 WHERE s.salutation_key = :key AND t.language_id = :languageId AND t.display_name = :stock',
                ['displayName' => $displayName, 'letterName' => $letterName, 'key' => $key, 'stock' => $stock, 'languageId' => $languageId, 'now' => $this->now()]
            );
        }
    }

    private function paymentMethods(Connection $connection, string $languageId): void
    {
        foreach (self::PAYMENT_METHODS as $technicalName => [$stock, $name, $description]) {
            $connection->executeStatement(
                'UPDATE payment_method_translation t JOIN payment_method p ON p.id = t.payment_method_id
                 SET t.name = :name, t.description = :description, t.updated_at = :now
                 WHERE p.technical_name = :technicalName AND t.language_id = :languageId AND t.name = :stock',
                ['name' => $name, 'description' => $description, 'technicalName' => $technicalName, 'stock' => $stock, 'languageId' => $languageId, 'now' => $this->now()]
            );
        }
    }

    private function shippingMethods(Connection $connection, string $languageId): void
    {
        foreach (self::SHIPPING_METHODS as $technicalName => [$stock, $name]) {
            $connection->executeStatement(
                'UPDATE shipping_method_translation t JOIN shipping_method s ON s.id = t.shipping_method_id
                 SET t.name = :name, t.updated_at = :now
                 WHERE s.technical_name = :technicalName AND t.language_id = :languageId AND t.name = :stock',
                ['name' => $name, 'technicalName' => $technicalName, 'stock' => $stock, 'languageId' => $languageId, 'now' => $this->now()]
            );
        }
    }

    private function deliveryTimes(Connection $connection, string $languageId): void
    {
        foreach (self::DELIVERY_TIMES as $stock => $name) {
            $connection->executeStatement(
                'UPDATE delivery_time_translation SET name = :name, updated_at = :now
                 WHERE language_id = :languageId AND name = :stock',
                ['name' => $name, 'stock' => $stock, 'languageId' => $languageId, 'now' => $this->now()]
            );
        }
    }

    private function states(Connection $connection, string $languageId): void
    {
        foreach (self::STATES as $machine => $states) {
            foreach ($states as $state => $name) {
                $connection->executeStatement(
                    'UPDATE state_machine_state_translation t
                     JOIN state_machine_state s ON s.id = t.state_machine_state_id
                     JOIN state_machine m ON m.id = s.state_machine_id
                     SET t.name = :name, t.updated_at = :now
                     WHERE m.technical_name = :machine AND s.technical_name = :state
                       AND t.language_id = :languageId AND t.name IN (:stock)',
                    ['name' => $name, 'machine' => $machine, 'state' => $state, 'languageId' => $languageId, 'stock' => self::STOCK_STATE_NAMES, 'now' => $this->now()],
                    ['stock' => ArrayParameterType::STRING]
                );
            }
        }
    }

    private function currency(Connection $connection, string $languageId): void
    {
        $connection->executeStatement(
            "UPDATE currency_translation SET name = 'Evro', updated_at = :now
             WHERE language_id = :languageId AND name = 'Euro'",
            ['languageId' => $languageId, 'now' => $this->now()]
        );
    }

    /**
     * Slovenian country names come from ICU (ext-intl, which Shopware requires).
     * Replaced when never edited, or when the name still equals ICU's English name or
     * Shopware's own English install name (two rows were touched at install time).
     */
    private function countries(Connection $connection, string $languageId): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT t.country_id, t.name, t.updated_at, c.iso
             FROM country_translation t JOIN country c ON c.id = t.country_id
             WHERE t.language_id = :languageId',
            ['languageId' => $languageId]
        );

        foreach ($rows as $row) {
            $iso = (string) $row['iso'];
            $slovenian = self::COUNTRY_NAME_OVERRIDES[$iso] ?? \Locale::getDisplayRegion('-' . $iso, 'sl');
            if ($slovenian === '' || $slovenian === $iso || $slovenian === $row['name']) {
                continue;
            }

            $untouched = $row['updated_at'] === null
                || $row['name'] === \Locale::getDisplayRegion('-' . $iso, 'en')
                || ($iso === 'US' && $row['name'] === 'United States of America');
            if (!$untouched) {
                continue;
            }

            $connection->update(
                'country_translation',
                ['name' => $slovenian, 'updated_at' => $this->now()],
                ['country_id' => $row['country_id'], 'language_id' => $languageId]
            );
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
