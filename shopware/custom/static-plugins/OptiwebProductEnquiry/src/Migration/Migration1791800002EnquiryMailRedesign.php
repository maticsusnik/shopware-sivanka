<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Two mails for every product enquiry, both styled for the Sivanka mail header and
 * footer (OptiwebTheme), which wrap them in the branded card:
 *
 *  - product_enquiry_form          the shop's notification, redesigned
 *  - product_enquiry_confirmation  new: the customer's "we received it" mail
 *
 * The notification is only rewritten where it still holds text an earlier plugin
 * version generated. Anything edited in the admin is left alone.
 *
 * Every language gets a translation: Slovenian for an sl-* locale, English
 * otherwise.
 */
class Migration1791800002EnquiryMailRedesign extends MigrationStep
{
    private const NOTIFICATION_TYPE = 'product_enquiry_form';
    private const CONFIRMATION_TYPE = 'product_enquiry_confirmation';

    /** Opening markup of the notification bodies earlier plugin versions wrote. */
    private const GENERATED_SIGNATURES = [
        '<div style="font-family: Arial, sans-serif; max-width: 600px;">',
        '<div style="font-family:arial; font-size:12px;">',
    ];

    /** Subjects earlier plugin versions wrote, replaced together with the body. */
    private const GENERATED_SUBJECTS = [
        'Povpraševanje po izdelku',
        'Product Enquiry',
        'Product enquiry form',
    ];

    public function getCreationTimestamp(): int
    {
        return 1791800002;
    }

    public function update(Connection $connection): void
    {
        $locales = $this->languageLocales($connection);

        $this->createConfirmationTemplate($connection, $locales);
        $this->redesignNotification($connection, $locales);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * @param array<string, string> $locales language id (bytes) => locale code
     */
    private function createConfirmationTemplate(Connection $connection, array $locales): void
    {
        $now = $this->now();

        $typeId = $connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => self::CONFIRMATION_TYPE]
        );

        if (!$typeId) {
            $typeId = Uuid::randomBytes();

            $connection->insert('mail_template_type', [
                'id'                 => $typeId,
                'technical_name'     => self::CONFIRMATION_TYPE,
                'available_entities' => json_encode(['salesChannel' => 'sales_channel']),
                'created_at'         => $now,
            ]);
        }

        foreach ($locales as $languageId => $locale) {
            $exists = $connection->fetchOne(
                'SELECT 1 FROM mail_template_type_translation WHERE mail_template_type_id = :typeId AND language_id = :languageId',
                ['typeId' => $typeId, 'languageId' => $languageId]
            );

            if (!$exists) {
                $connection->insert('mail_template_type_translation', [
                    'mail_template_type_id' => $typeId,
                    'language_id'           => $languageId,
                    'name'                  => $this->isSlovenian($locale) ? 'Potrditev povpraševanja (kupec)' : 'Product enquiry confirmation (customer)',
                    'created_at'            => $now,
                ]);
            }
        }

        $templateId = $connection->fetchOne(
            'SELECT id FROM mail_template WHERE mail_template_type_id = :typeId',
            ['typeId' => $typeId]
        );

        if (!$templateId) {
            $templateId = Uuid::randomBytes();

            $connection->insert('mail_template', [
                'id'                    => $templateId,
                'mail_template_type_id' => $typeId,
                'system_default'        => 1,
                'created_at'            => $now,
            ]);
        }

        foreach ($locales as $languageId => $locale) {
            $exists = $connection->fetchOne(
                'SELECT 1 FROM mail_template_translation WHERE mail_template_id = :templateId AND language_id = :languageId',
                ['templateId' => $templateId, 'languageId' => $languageId]
            );

            if ($exists) {
                continue;
            }

            $sl = $this->isSlovenian($locale);

            $connection->insert('mail_template_translation', [
                'mail_template_id' => $templateId,
                'language_id'      => $languageId,
                'sender_name'      => '{{ salesChannel.translated.name }}',
                'subject'          => $sl ? 'Prejeli smo vaše povpraševanje' : 'We have received your enquiry',
                'description'      => $sl ? 'Potrditev, ki jo kupec prejme po oddanem povpraševanju.' : 'Sent to the customer after they submit a product enquiry.',
                'content_html'     => $this->confirmationHtml($sl),
                'content_plain'    => $this->confirmationPlain($sl),
                'created_at'       => $now,
            ]);
        }
    }

    /**
     * @param array<string, string> $locales language id (bytes) => locale code
     */
    private function redesignNotification(Connection $connection, array $locales): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT t.mail_template_id, t.language_id, t.subject, t.content_html
             FROM mail_template_translation t
             JOIN mail_template m ON m.id = t.mail_template_id
             JOIN mail_template_type y ON y.id = m.mail_template_type_id
             WHERE y.technical_name = :name',
            ['name' => self::NOTIFICATION_TYPE]
        );

        foreach ($rows as $row) {
            if (!$this->isGenerated((string) $row['content_html'])) {
                continue;
            }

            $sl = $this->isSlovenian($locales[$row['language_id']] ?? '');

            $update = [
                'content_html'  => $this->notificationHtml($sl),
                'content_plain' => $this->notificationPlain($sl),
                'updated_at'    => $this->now(),
            ];

            if (\in_array(trim((string) $row['subject']), self::GENERATED_SUBJECTS, true)) {
                $update['subject'] = $sl
                    ? 'Novo povpraševanje: {{ contactFormData.firstName }} {{ contactFormData.lastName }}'
                    : 'New enquiry: {{ contactFormData.firstName }} {{ contactFormData.lastName }}';
            }

            $connection->update('mail_template_translation', $update, [
                'mail_template_id' => $row['mail_template_id'],
                'language_id'      => $row['language_id'],
            ]);
        }
    }

    private function isGenerated(string $html): bool
    {
        $html = ltrim($html);

        foreach (self::GENERATED_SIGNATURES as $signature) {
            if (str_starts_with($html, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> language id (bytes) => locale code */
    private function languageLocales(Connection $connection): array
    {
        $locales = [];

        foreach ($connection->fetchAllAssociative('SELECT l.id, lo.code FROM language l JOIN locale lo ON lo.id = l.locale_id') as $row) {
            $locales[$row['id']] = (string) $row['code'];
        }

        // The system language always gets a translation, it is the fallback for all others.
        $systemLanguage = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $locales[$systemLanguage] ??= 'en-GB';

        return $locales;
    }

    private function isSlovenian(string $locale): bool
    {
        return str_starts_with($locale, 'sl');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    // ---------------------------------------------------------------- templates

    private function confirmationHtml(bool $sl): string
    {
        $t = $sl ? [
            '%heading%'  => 'Hvala za vaše povpraševanje',
            '%greeting%' => 'Pozdravljeni, {{ contactFormData.firstName }}!',
            '%intro%'    => 'Vaše povpraševanje smo prejeli. Pregledali ga bomo in vam odgovorili v najkrajšem možnem času. Spodaj je povzetek tega, kar ste nam poslali.',
            '%products%' => 'Izdelki',
            '%yourData%' => 'Vaši podatki',
            '%outro%'    => 'Če želite povpraševanje dopolniti, preprosto odgovorite na to sporočilo.',
            '%signoff%'  => 'Lep pozdrav,<br>Trgovina ŠIVANKA',
        ] : [
            '%heading%'  => 'Thank you for your enquiry',
            '%greeting%' => 'Hello {{ contactFormData.firstName }},',
            '%intro%'    => 'We have received your enquiry. We will look into it and get back to you as soon as possible. Here is a summary of what you sent us.',
            '%products%' => 'Products',
            '%yourData%' => 'Your details',
            '%outro%'    => 'If you would like to add anything, simply reply to this email.',
            '%signoff%'  => 'Kind regards,<br>Trgovina ŠIVANKA',
        ];

        $html = <<<'TWIG'
<h1 style="margin:0 0 16px;font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:1.3;font-weight:normal;color:#5C1229;">%heading%</h1>
<p style="margin:0 0 12px;">%greeting%</p>
<p style="margin:0 0 24px;">%intro%</p>
<p style="margin:0 0 4px;font-weight:bold;color:#5C1229;">%products%</p>
%lines%
%message%
<p style="margin:0 0 4px;font-weight:bold;color:#5C1229;">%yourData%</p>
%customer%
<p style="margin:0 0 16px;">%outro%</p>
<p style="margin:0;">%signoff%</p>
TWIG;

        return strtr($html, $t + [
            '%lines%'    => $this->linesHtml($sl),
            '%message%'  => $this->messageHtml($sl),
            '%customer%' => $this->customerHtml($sl),
        ]);
    }

    private function confirmationPlain(bool $sl): string
    {
        $t = $sl ? [
            '%heading%'  => 'Hvala za vaše povpraševanje',
            '%greeting%' => 'Pozdravljeni, {{ contactFormData.firstName }}!',
            '%intro%'    => 'Vaše povpraševanje smo prejeli. Pregledali ga bomo in vam odgovorili v najkrajšem možnem času. Spodaj je povzetek tega, kar ste nam poslali.',
            '%outro%'    => 'Če želite povpraševanje dopolniti, preprosto odgovorite na to sporočilo.',
            '%signoff%'  => "Lep pozdrav,\nTrgovina ŠIVANKA",
        ] : [
            '%heading%'  => 'Thank you for your enquiry',
            '%greeting%' => 'Hello {{ contactFormData.firstName }},',
            '%intro%'    => 'We have received your enquiry. We will look into it and get back to you as soon as possible. Here is a summary of what you sent us.',
            '%outro%'    => 'If you would like to add anything, simply reply to this email.',
            '%signoff%'  => "Kind regards,\nTrgovina ŠIVANKA",
        ];

        $plain = <<<'TWIG'
%heading%

%greeting%

%intro%

%lines%

%message%

%customer%

%outro%

%signoff%
TWIG;

        return strtr($plain, $t + [
            '%lines%'    => $this->linesPlain($sl),
            '%message%'  => $this->messagePlain($sl),
            '%customer%' => $this->customerPlain($sl),
        ]);
    }

    private function notificationHtml(bool $sl): string
    {
        $t = $sl ? [
            '%heading%'  => 'Novo povpraševanje',
            '%intro%'    => 'Prejeto prek spletne trgovine. Na to sporočilo lahko odgovorite neposredno, odgovor bo prejel kupec.',
            '%customerHeading%' => 'Kupec',
            '%products%' => 'Izdelki',
            '%open%'     => 'Odpri v administraciji',
        ] : [
            '%heading%'  => 'New product enquiry',
            '%intro%'    => 'Received through the online shop. You can reply to this email directly, the reply goes to the customer.',
            '%customerHeading%' => 'Customer',
            '%products%' => 'Products',
            '%open%'     => 'Open in the administration',
        ];

        $html = <<<'TWIG'
<h1 style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:1.3;font-weight:normal;color:#5C1229;">%heading%</h1>
<p style="margin:0 0 24px;color:#6B5A55;font-size:14px;">%intro%</p>
<p style="margin:0 0 4px;font-weight:bold;color:#5C1229;">%customerHeading%</p>
%customer%
<p style="margin:0 0 4px;font-weight:bold;color:#5C1229;">%products% ({{ enquiryLines|length }})</p>
%lines%
%message%
{% if adminUrl is defined and adminUrl %}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0;">
<tr>
<td style="border-radius:4px;background-color:#8E2247;"><a href="{{ adminUrl }}" style="display:inline-block;padding:12px 24px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;color:#FFFFFF;text-decoration:none;">%open%</a></td>
</tr>
</table>
{% endif %}
TWIG;

        return strtr($html, $t + [
            '%lines%'    => $this->linesHtml($sl),
            '%message%'  => $this->messageHtml($sl),
            '%customer%' => $this->customerHtml($sl),
        ]);
    }

    private function notificationPlain(bool $sl): string
    {
        $t = $sl ? [
            '%heading%' => 'Novo povpraševanje',
            '%open%'    => 'Odpri v administraciji',
        ] : [
            '%heading%' => 'New product enquiry',
            '%open%'    => 'Open in the administration',
        ];

        $plain = <<<'TWIG'
%heading%

%customer%

%lines%

%message%

{% if adminUrl is defined and adminUrl %}
%open%: {{ adminUrl }}
{% endif %}
TWIG;

        return strtr($plain, $t + [
            '%lines%'    => $this->linesPlain($sl),
            '%message%'  => $this->messagePlain($sl),
            '%customer%' => $this->customerPlain($sl),
        ]);
    }

    // ------------------------------------------------------------ shared parts

    private function linesHtml(bool $sl): string
    {
        $t = $sl
            ? ['%number%' => 'Šifra', '%variant%' => 'Varianta', '%quantity%' => 'Količina']
            : ['%number%' => 'Item no.', '%variant%' => 'Variant', '%quantity%' => 'Quantity'];

        return strtr(<<<'TWIG'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 24px;">
{% for line in enquiryLines %}
<tr>
<td width="76" valign="top" style="width:76px;padding:12px 12px 12px 0;border-bottom:1px solid #EADFD1;">
{% if line.imageUrl is defined and line.imageUrl %}<img src="{{ line.imageUrl }}" width="64" alt="" style="display:block;width:64px;height:auto;border:1px solid #EADFD1;border-radius:4px;">{% endif %}
</td>
<td valign="top" style="padding:12px 0;border-bottom:1px solid #EADFD1;font-size:14px;line-height:1.5;">
{% if line.productUrl is defined and line.productUrl %}<a href="{{ line.productUrl }}" style="color:#2E1A20;font-weight:bold;text-decoration:none;">{{ line.productName }}</a>{% else %}<strong>{{ line.productName }}</strong>{% endif %}<br>
<span style="color:#6B5A55;font-size:13px;">%number%: {{ line.productNumber }}{% if line.productOption %} &middot; %variant%: {{ line.productOption }}{% endif %}</span>
</td>
<td align="right" valign="top" style="padding:12px 0 12px 12px;border-bottom:1px solid #EADFD1;font-size:14px;white-space:nowrap;"><span style="color:#6B5A55;font-size:13px;">%quantity%</span><br><strong>{{ line.quantity }}</strong></td>
</tr>
{% endfor %}
</table>
TWIG, $t);
    }

    private function linesPlain(bool $sl): string
    {
        $t = $sl
            ? ['%products%' => 'Izdelki', '%number%' => 'šifra', '%variant%' => 'varianta', '%quantity%' => 'količina']
            : ['%products%' => 'Products', '%number%' => 'item no.', '%variant%' => 'variant', '%quantity%' => 'quantity'];

        return strtr(<<<'TWIG'
%products% ({{ enquiryLines|length }}):
{% for line in enquiryLines %}
- {{ line.productName }} (%number% {{ line.productNumber }}{% if line.productOption %}, %variant% {{ line.productOption }}{% endif %}), %quantity%: {{ line.quantity }}
{% endfor %}
TWIG, $t);
    }

    private function messageHtml(bool $sl): string
    {
        $heading = $sl ? 'Sporočilo' : 'Message';

        return <<<TWIG
{% if contactFormData.comment|default %}
<p style="margin:0 0 4px;font-weight:bold;color:#5C1229;">{$heading}</p>
<div style="margin:0 0 24px;padding:14px 16px;background-color:#FDF8F2;border-left:3px solid #8E2247;font-size:14px;">{{ contactFormData.comment|nl2br }}</div>
{% endif %}
TWIG;
    }

    private function messagePlain(bool $sl): string
    {
        $heading = $sl ? 'Sporočilo' : 'Message';

        return <<<TWIG
{% if contactFormData.comment|default %}
{$heading}:
{{ contactFormData.comment }}
{% endif %}
TWIG;
    }

    private function customerHtml(bool $sl): string
    {
        $t = $sl
            ? ['%name%' => 'Ime', '%email%' => 'E-pošta', '%phone%' => 'Telefon']
            : ['%name%' => 'Name', '%email%' => 'Email', '%phone%' => 'Phone'];

        return strtr(<<<'TWIG'
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;font-size:14px;line-height:1.5;">
<tr><td style="padding:2px 16px 2px 0;color:#6B5A55;">%name%</td><td style="padding:2px 0;">{{ contactFormData.firstName }} {{ contactFormData.lastName }}</td></tr>
<tr><td style="padding:2px 16px 2px 0;color:#6B5A55;">%email%</td><td style="padding:2px 0;"><a href="mailto:{{ contactFormData.email }}" style="color:#8E2247;text-decoration:none;">{{ contactFormData.email }}</a></td></tr>
{% if contactFormData.phone|default %}<tr><td style="padding:2px 16px 2px 0;color:#6B5A55;">%phone%</td><td style="padding:2px 0;"><a href="tel:{{ contactFormData.phone|replace({' ': ''}) }}" style="color:#8E2247;text-decoration:none;">{{ contactFormData.phone }}</a></td></tr>{% endif %}
</table>
TWIG, $t);
    }

    private function customerPlain(bool $sl): string
    {
        $t = $sl
            ? ['%name%' => 'Ime', '%email%' => 'E-pošta', '%phone%' => 'Telefon']
            : ['%name%' => 'Name', '%email%' => 'Email', '%phone%' => 'Phone'];

        return strtr(<<<'TWIG'
%name%: {{ contactFormData.firstName }} {{ contactFormData.lastName }}
%email%: {{ contactFormData.email }}
{% if contactFormData.phone|default %}%phone%: {{ contactFormData.phone }}
{% endif %}
TWIG, $t);
    }
}
