<?php declare(strict_types=1);

namespace OptiwebTheme\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Branded header and footer wrapped around every mail the storefront sends
 * (order mails, account mails, the product enquiry mails).
 *
 * Shopware concatenates header + mail body + footer before rendering, so the
 * header opens the card layout and the footer closes it again. Both are plain
 * mail_header_footer rows, editable later under Settings → Email templates →
 * Header & footer.
 *
 * The header is only assigned to storefront sales channels that have none yet,
 * so a header someone picked in the admin is never replaced.
 */
class Migration1791800001SivankaMailHeaderFooter extends MigrationStep
{
    private const NAME = 'Sivanka';

    public function getCreationTimestamp(): int
    {
        return 1791800001;
    }

    public function update(Connection $connection): void
    {
        $id = Uuid::fromHexToBytes(Uuid::fromStringToHex('optiweb-theme.mail-header-footer.sivanka'));
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $exists = $connection->fetchOne('SELECT 1 FROM mail_header_footer WHERE id = :id', ['id' => $id]);

        if (!$exists) {
            $connection->insert('mail_header_footer', [
                'id'             => $id,
                'system_default' => 0,
                'created_at'     => $now,
            ]);

            $connection->insert('mail_header_footer_translation', [
                'mail_header_footer_id' => $id,
                'language_id'           => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'name'                  => self::NAME,
                'description'           => 'Sivanka logo above and shop contact details below every mail.',
                'header_html'           => $this->headerHtml(),
                'header_plain'          => '',
                'footer_html'           => $this->footerHtml(),
                'footer_plain'          => $this->footerPlain(),
                'created_at'            => $now,
            ]);
        }

        $connection->executeStatement(
            'UPDATE sales_channel SET mail_header_footer_id = :id, updated_at = :now
             WHERE mail_header_footer_id IS NULL AND type_id = :storefront',
            [
                'id'         => $id,
                'now'        => $now,
                'storefront' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT),
            ]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * Opens the page background, the white card and the body cell. Layout is
     * tables and inline styles only, because that is what mail clients render.
     */
    private function headerHtml(): string
    {
        return <<<'TWIG'
{% set shopUrl = 'https://www.sivanka.com' %}
{% if salesChannel is defined and salesChannel and salesChannel.domains is not empty %}
    {% set shopUrl = (salesChannel.domains|first).url %}
{% endif %}
<div style="margin:0;padding:0;background-color:#FDF8F2;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FDF8F2;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#FFFFFF;border:1px solid #EADFD1;border-radius:8px;">
<tr>
<td align="center" style="padding:28px 32px 22px;border-bottom:3px solid #8E2247;">
<a href="{{ shopUrl }}" style="text-decoration:none;"><img src="{{ shopUrl }}/bundles/optiwebtheme/assets/images/mail/sivanka-logo.jpg" width="216" height="44" alt="Šivanka" style="display:block;border:0;width:216px;height:auto;"></a>
</td>
</tr>
<tr>
<td style="padding:32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#2E1A20;">
TWIG;
    }

    /** Closes the body cell, adds the shop details and closes the card. */
    private function footerHtml(): string
    {
        return <<<'TWIG'
{% set shopUrl = 'https://www.sivanka.com' %}
{% if salesChannel is defined and salesChannel and salesChannel.domains is not empty %}
    {% set shopUrl = (salesChannel.domains|first).url %}
{% endif %}
{% set linkStyle = 'color:#6B5A55;text-decoration:none;' %}
</td>
</tr>
<tr>
<td style="padding:24px 32px;background-color:#FDF8F2;border-top:1px solid #EADFD1;border-radius:0 0 8px 8px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#6B5A55;">
<p style="margin:0 0 10px;">Cesta Staneta Žagarja 32, 4000 Kranj<br>
PON–ČET 8:00–17:00 &middot; PET 8:00–14:00 &middot; SOB zaprto</p>
<p style="margin:0;"><a href="tel:+38631413597" style="{{ linkStyle }}">031 413 597</a> &middot; <a href="tel:+38659031676" style="{{ linkStyle }}">05 90 31 676</a><br>
<a href="mailto:info@sivanka.com" style="{{ linkStyle }}">info@sivanka.com</a> &middot; <a href="{{ shopUrl }}" style="{{ linkStyle }}">sivanka.com</a></p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</div>
TWIG;
    }

    private function footerPlain(): string
    {
        return <<<'TWIG'


--
Cesta Staneta Žagarja 32, 4000 Kranj
PON–ČET 8:00–17:00, PET 8:00–14:00, SOB zaprto
031 413 597 / 05 90 31 676
info@sivanka.com
sivanka.com
TWIG;
    }
}
