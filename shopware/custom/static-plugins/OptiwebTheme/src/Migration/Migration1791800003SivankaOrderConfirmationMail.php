<?php declare(strict_types=1);

namespace OptiwebTheme\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Order confirmation in the Sivanka mail layout (the header/footer from
 * Migration1791800001 wraps it in the branded card), in Slovenian for sl-* languages.
 *
 * Shows everything Shopware's default template does — nested line items with
 * options and reference price, shipping, net/tax/rounded totals, payment and
 * shipping method, both addresses, VAT id, the order deep link, accessible
 * documents and the legal guarantee notice — just laid out as tables with inline
 * styles that mail clients render.
 *
 * Only translations that still hold Shopware's stock template are replaced, so a
 * confirmation edited in the admin is never overwritten.
 */
class Migration1791800003SivankaOrderConfirmationMail extends MigrationStep
{
    /** Phrases only Shopware's stock order confirmation contains (en / de). */
    private const STOCK_MARKERS = [
        'We have received your order from',
        'wir haben Ihre Bestellung',
    ];

    private const STOCK_SUBJECTS = ['Order confirmation', 'Bestellbestätigung'];

    public function getCreationTimestamp(): int
    {
        return 1791800003;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT t.mail_template_id, t.language_id, t.subject, t.content_html, lo.code
             FROM mail_template_translation t
             JOIN mail_template m ON m.id = t.mail_template_id
             JOIN mail_template_type y ON y.id = m.mail_template_type_id
             JOIN language l ON l.id = t.language_id
             JOIN locale lo ON lo.id = l.locale_id
             WHERE y.technical_name = :type',
            ['type' => 'order_confirmation_mail']
        );

        foreach ($rows as $row) {
            if (!$this->isStock((string) $row['content_html'])) {
                continue;
            }

            $sl = str_starts_with((string) $row['code'], 'sl');

            $update = [
                'content_html'  => $this->html($sl),
                'content_plain' => $this->plain($sl),
                'updated_at'    => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ];

            if (\in_array(trim((string) $row['subject']), self::STOCK_SUBJECTS, true)) {
                $update['subject'] = $sl
                    ? 'Potrditev naročila {{ order.orderNumber }}'
                    : 'Order confirmation {{ order.orderNumber }}';
            }

            $connection->update('mail_template_translation', $update, [
                'mail_template_id' => $row['mail_template_id'],
                'language_id'      => $row['language_id'],
            ]);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function isStock(string $html): bool
    {
        foreach (self::STOCK_MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function texts(bool $sl): array
    {
        return $sl ? [
            '%locale%'        => 'sl-SI',
            '%heading%'       => 'Hvala za vaše naročilo',
            '%greeting%'      => 'Pozdravljeni, {{ order.orderCustomer.firstName }}!',
            '%intro%'         => 'Vaše naročilo smo prejeli in ga že pripravljamo. Ko bo plačilo potrjeno, vas bomo obvestili v ločenem sporočilu.',
            '%orderNumber%'   => 'Številka naročila',
            '%orderDate%'     => 'Datum',
            '%viewOrder%'     => 'Poglej naročilo',
            '%products%'      => 'Izdelki',
            '%number%'        => 'Šifra',
            '%per%'           => 'za',
            '%shipping%'      => 'Dostava',
            '%net%'           => 'Skupaj brez DDV',
            '%plusTax%'       => 'DDV',
            '%total%'         => 'Za plačilo',
            '%roundedTotal%'  => 'Zaokroženo za plačilo',
            '%payment%'       => 'Način plačila',
            '%shippingType%'  => 'Način dostave',
            '%billing%'       => 'Naslov za račun',
            '%shippingAddr%'  => 'Naslov za dostavo',
            '%vatId%'         => 'Vaša ID številka za DDV',
            '%vatNote%'       => 'Če je naročilo uspešno in imate sedež v državi EU, vam blago dobavimo brez DDV.',
            '%statusNote%'    => 'Stanje naročila lahko kadarkoli preverite v svojem računu pod »Moja naročila« ali prek gumba zgoraj. Pri nakupu brez registracije za dostop uporabite e-poštni naslov in poštno številko naslova za račun.',
            '%questions%'     => 'Če imate vprašanja, nam preprosto odgovorite na to sporočilo.',
            '%signoff%'       => 'Lep pozdrav,<br>Trgovina ŠIVANKA',
            '%a11yIntro%'     => 'Za boljšo dostopnost so dokumenti na voljo tudi v obliki HTML:',
            '%a11yNote%'      => 'Zaradi varstva podatkov je za ogled potrebna prijava; pri nakupu brez registracije uporabite e-poštni naslov in poštno številko naslova za račun.',
            '%guarantee%'     => 'Obvestilo o zakonski garanciji',
        ] : [
            '%locale%'        => 'en-GB',
            '%heading%'       => 'Thank you for your order',
            '%greeting%'      => 'Hello {{ order.orderCustomer.firstName }},',
            '%intro%'         => 'We have received your order and are getting it ready. Once your payment is confirmed, you will receive a separate notification.',
            '%orderNumber%'   => 'Order number',
            '%orderDate%'     => 'Date',
            '%viewOrder%'     => 'View order',
            '%products%'      => 'Products',
            '%number%'        => 'Item no.',
            '%per%'           => 'per',
            '%shipping%'      => 'Shipping',
            '%net%'           => 'Net total',
            '%plusTax%'       => 'VAT',
            '%total%'         => 'Total',
            '%roundedTotal%'  => 'Rounded total',
            '%payment%'       => 'Payment method',
            '%shippingType%'  => 'Shipping method',
            '%billing%'       => 'Billing address',
            '%shippingAddr%'  => 'Shipping address',
            '%vatId%'         => 'Your VAT ID',
            '%vatNote%'       => 'If your order is successful and you are based in an EU country, you will receive your goods exempt from VAT.',
            '%statusNote%'    => 'You can check the status of your order at any time under "My account" - "My orders" or with the button above. For guest orders, use your email address and the postal code of the billing address.',
            '%questions%'     => 'If you have any questions, simply reply to this email.',
            '%signoff%'       => 'Kind regards,<br>Trgovina ŠIVANKA',
            '%a11yIntro%'     => 'For better accessibility, the documents are also available as HTML:',
            '%a11yNote%'      => 'For data protection reasons, viewing them requires a login; for guest orders use your email address and the postal code of the billing address.',
            '%guarantee%'     => 'Notice on your legal guarantee rights',
        ];
    }

    private function html(bool $sl): string
    {
        $template = <<<'TWIG'
{% set currencyIsoCode = order.currency.isoCode %}
{% set shopUrl = salesChannel.domains is not empty ? (salesChannel.domains|first).url : 'https://www.sivanka.com' %}
{% set orderUrl = rawUrl('frontend.account.order.single.page', { 'deepLinkCode': order.deepLinkCode }, shopUrl) %}
{% set delivery = order.deliveries.first %}
{% set label = 'margin:0 0 4px;font-weight:bold;color:#5C1229;' %}
{% set cell = 'padding:12px 0;border-bottom:1px solid #EADFD1;font-size:14px;line-height:1.5;vertical-align:top;' %}
{% set muted = 'color:#6B5A55;font-size:13px;' %}
<h1 style="margin:0 0 16px;font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:1.3;font-weight:normal;color:#5C1229;">%heading%</h1>
<p style="margin:0 0 12px;">%greeting%</p>
<p style="margin:0 0 24px;">%intro%</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;background-color:#FDF8F2;border:1px solid #EADFD1;border-radius:6px;">
<tr>
<td width="50%" style="width:50%;padding:16px 20px 12px;font-size:14px;line-height:1.5;vertical-align:top;">
<span style="{{ muted }}">%orderNumber%</span><br><strong style="font-size:16px;">{{ order.orderNumber }}</strong>
</td>
<td width="50%" style="width:50%;padding:16px 20px 12px;font-size:14px;line-height:1.5;vertical-align:top;">
<span style="{{ muted }}">%orderDate%</span><br>{{ order.orderDateTime|format_datetime('medium', 'short', locale='%locale%') }}
</td>
</tr>
<tr>
<td colspan="2" style="padding:0 20px 16px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td style="border-radius:4px;background-color:#8E2247;"><a href="{{ orderUrl }}" style="display:inline-block;padding:10px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;color:#FFFFFF;text-decoration:none;white-space:nowrap;">%viewOrder%</a></td>
</tr></table>
</td>
</tr>
</table>

<p style="{{ label }}">%products%</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 8px;">
{% for lineItem in order.nestedLineItems %}
    {% set nestingLevel = 0 %}
    {% set nestedItem = lineItem %}
    {% block lineItem %}
<tr>
{% set hasImage = nestedItem.cover is defined and nestedItem.cover is not null %}
{% if hasImage %}
<td width="76" style="width:76px;padding:12px 12px 12px 0;border-bottom:1px solid #EADFD1;vertical-align:top;"><img src="{{ nestedItem.cover.url }}" width="64" alt="" style="display:block;width:64px;height:auto;border:1px solid #EADFD1;border-radius:4px;"></td>
{% endif %}
<td{% if not hasImage %} colspan="2"{% endif %} style="{{ cell }}{% if nestingLevel > 0 %}padding-left:{{ nestingLevel * 16 }}px;{% endif %}">
<strong>{{ nestedItem.label|u.wordwrap(80) }}</strong>
{% if nestedItem.payload.productNumber is defined %}<br><span style="{{ muted }}">%number%: {{ nestedItem.payload.productNumber }}</span>{% endif %}
{% if nestedItem.payload.options is defined and nestedItem.payload.options|length >= 1 %}
<br><span style="{{ muted }}">{% for option in nestedItem.payload.options %}{{ option.group }}: {{ option.option }}{% if not loop.last %} &middot; {% endif %}{% endfor %}</span>
{% endif %}
{% if nestedItem.payload.features is defined and nestedItem.payload.features|length >= 1 %}
    {% set referencePriceFeatures = nestedItem.payload.features|filter(feature => feature.type == 'referencePrice') %}
    {% if referencePriceFeatures|length >= 1 %}
        {% set referencePriceFeature = referencePriceFeatures|first %}
<br><span style="{{ muted }}">{{ referencePriceFeature.value.purchaseUnit }} {{ referencePriceFeature.value.unitName }} ({{ referencePriceFeature.value.price|currency(currencyIsoCode) }} %per% {{ referencePriceFeature.value.referenceUnit }} {{ referencePriceFeature.value.unitName }})</span>
    {% endif %}
{% endif %}
<br><span style="{{ muted }}">{{ nestedItem.quantity }} &times; {{ nestedItem.unitPrice|currency(currencyIsoCode) }}</span>
</td>
<td align="right" style="{{ cell }}padding-left:12px;white-space:nowrap;"><strong>{{ nestedItem.totalPrice|currency(currencyIsoCode) }}</strong></td>
</tr>
{% if nestedItem.productId is defined and nestedItem.productId %}
    {% set garanLabelDataUri = nestedItem.productId|sw_garan_label_nested_uri(context) %}
    {% if garanLabelDataUri %}
<tr><td colspan="3" style="padding:8px 0;"><img src="{{ garanLabelDataUri }}" alt=""></td></tr>
    {% endif %}
{% endif %}
{% if nestedItem.children.count > 0 %}
    {% set nestingLevel = nestingLevel + 1 %}
    {% for lineItem in nestedItem.children %}
        {% set nestedItem = lineItem %}
        {{ block('lineItem') }}
    {% endfor %}
{% endif %}
    {% endblock %}
{% endfor %}
</table>

{% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
{% set decimals = order.totalRounding.decimals %}
{% set total = order.price.totalPrice %}
{% if displayRounded %}
    {% set total = order.price.rawTotal %}
    {% set decimals = order.itemRounding.decimals %}
{% endif %}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;font-size:14px;line-height:1.5;">
{% for shippingCost in order.deliveries %}
<tr><td style="padding:3px 0;color:#6B5A55;">%shipping%</td><td align="right" style="padding:3px 0;">{{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}</td></tr>
{% endfor %}
<tr><td style="padding:3px 0;color:#6B5A55;">%net%</td><td align="right" style="padding:3px 0;">{{ order.amountNet|currency(currencyIsoCode) }}</td></tr>
{% for calculatedTax in order.price.calculatedTaxes %}
<tr><td style="padding:3px 0;color:#6B5A55;">%plusTax% {{ calculatedTax.taxRate }} %</td><td align="right" style="padding:3px 0;">{{ calculatedTax.tax|currency(currencyIsoCode) }}</td></tr>
{% endfor %}
<tr><td style="padding:10px 0 0;border-top:1px solid #EADFD1;font-weight:bold;font-size:16px;color:#5C1229;">%total%</td><td align="right" style="padding:10px 0 0;border-top:1px solid #EADFD1;font-weight:bold;font-size:16px;color:#5C1229;">{{ total|currency(currencyIsoCode, decimals=decimals) }}</td></tr>
{% if displayRounded %}
<tr><td style="padding:3px 0;font-weight:bold;">%roundedTotal%</td><td align="right" style="padding:3px 0;font-weight:bold;">{{ order.price.totalPrice|currency(currencyIsoCode, decimals=order.totalRounding.decimals) }}</td></tr>
{% endif %}
</table>

{% set billingAddress = order.addresses.get(order.billingAddressId) %}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;font-size:14px;line-height:1.5;">
<tr>
<td width="50%" style="width:50%;padding:0 12px 20px 0;vertical-align:top;">
{% if order.transactions is defined and order.transactions is not empty %}
<p style="{{ label }}">%payment%</p>
<p style="margin:0;">{{ order.transactions.first.paymentMethod.translated.name }}{% if order.transactions.first.paymentMethod.translated.description %}<br><span style="{{ muted }}">{{ order.transactions.first.paymentMethod.translated.description }}</span>{% endif %}</p>
{% endif %}
</td>
<td width="50%" style="width:50%;padding:0 0 20px 12px;vertical-align:top;">
{% if delivery %}
<p style="{{ label }}">%shippingType%</p>
<p style="margin:0;">{{ delivery.shippingMethod.translated.name }}{% if delivery.shippingMethod.translated.description %}<br><span style="{{ muted }}">{{ delivery.shippingMethod.translated.description }}</span>{% endif %}</p>
{% endif %}
</td>
</tr>
<tr>
<td width="50%" style="width:50%;padding:0 12px 0 0;vertical-align:top;">
<p style="{{ label }}">%billing%</p>
<p style="margin:0;">{% if billingAddress.company %}{{ billingAddress.company }}<br>{% endif %}{{ billingAddress.firstName }} {{ billingAddress.lastName }}<br>{{ billingAddress.street }}<br>{{ billingAddress.zipcode }} {{ billingAddress.city }}<br>{{ billingAddress.country.translated.name }}</p>
</td>
<td width="50%" style="width:50%;padding:0 0 0 12px;vertical-align:top;">
{% if delivery %}
{% set shippingAddress = delivery.shippingOrderAddress %}
<p style="{{ label }}">%shippingAddr%</p>
<p style="margin:0;">{% if shippingAddress.company %}{{ shippingAddress.company }}<br>{% endif %}{{ shippingAddress.firstName }} {{ shippingAddress.lastName }}<br>{{ shippingAddress.street }}<br>{{ shippingAddress.zipcode }} {{ shippingAddress.city }}<br>{{ shippingAddress.country.translated.name }}</p>
{% endif %}
</td>
</tr>
</table>

{% if order.orderCustomer.vatIds %}
<p style="margin:0 0 16px;font-size:14px;">%vatId%: {{ order.orderCustomer.vatIds|first }}<br><span style="{{ muted }}">%vatNote%</span></p>
{% endif %}

<p style="margin:0 0 12px;{{ muted }}">%statusNote%</p>

{% if a11yDocuments is defined and a11yDocuments is not empty %}
<p style="margin:0 0 6px;{{ muted }}">%a11yIntro%</p>
<ul style="margin:0 0 6px;padding-left:18px;{{ muted }}">
{% for a11y in a11yDocuments %}
    {% set documentLink = rawUrl('frontend.account.order.single.document.a11y', { documentId: a11y.documentId, deepLinkCode: a11y.deepLinkCode, fileType: a11y.fileExtension }, shopUrl) %}
<li><a href="{{ documentLink }}" target="_blank" style="color:#8E2247;">{{ documentLink }}</a></li>
{% endfor %}
</ul>
<p style="margin:0 0 12px;{{ muted }}">%a11yNote%</p>
{% endif %}

{% if config('core.cart.showLegalGuaranteeNotice') %}
<p style="margin:0 0 12px;{{ muted }}">%guarantee%: <a href="{{ context.languageId|sw_legal_guarantee_notice_link }}" target="_blank" style="color:#8E2247;">{{ context.languageId|sw_legal_guarantee_notice_link }}</a></p>
{% endif %}

<p style="margin:16px 0;">%questions%</p>
<p style="margin:0;">%signoff%</p>
TWIG;

        return strtr($template, $this->texts($sl));
    }

    private function plain(bool $sl): string
    {
        $template = <<<'TWIG'
{% set currencyIsoCode = order.currency.isoCode %}
{% set shopUrl = salesChannel.domains is not empty ? (salesChannel.domains|first).url : 'https://www.sivanka.com' %}
{% set delivery = order.deliveries.first %}
%heading%

%greeting%

%intro%

%orderNumber%: {{ order.orderNumber }}
%orderDate%: {{ order.orderDateTime|format_datetime('medium', 'short', locale='%locale%') }}
%viewOrder%: {{ rawUrl('frontend.account.order.single.page', { 'deepLinkCode': order.deepLinkCode }, shopUrl) }}

%products%:{{ "\n" }}
{%- for lineItem in order.nestedLineItems -%}
{%- set nestingLevel = 0 -%}
{%- set nestedItem = lineItem -%}
{%- block lineItem -%}
{%- if nestingLevel > 0 %}{% for i in 1..nestingLevel %}  {% endfor %}{% endif -%}
- {{ nestedItem.label|u.wordwrap(80) }}{% if nestedItem.payload.productNumber is defined %} (%number% {{ nestedItem.payload.productNumber }}){% endif %}{% if nestedItem.payload.options is defined and nestedItem.payload.options|length >= 1 %}, {% for option in nestedItem.payload.options %}{{ option.group }}: {{ option.option }}{% if not loop.last %}, {% endif %}{% endfor %}{% endif %}{{ "\n" }}  {{ nestedItem.quantity }} x {{ nestedItem.unitPrice|currency(currencyIsoCode) }} = {{ nestedItem.totalPrice|currency(currencyIsoCode) }}{{ "\n" }}
{%- if nestedItem.children.count > 0 -%}
{%- set nestingLevel = nestingLevel + 1 -%}
{%- for lineItem in nestedItem.children -%}
{%- set nestedItem = lineItem -%}
{{- block('lineItem') -}}
{%- endfor -%}
{%- endif -%}
{%- endblock -%}
{%- endfor %}


{% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
{% set decimals = order.totalRounding.decimals %}
{% set total = order.price.totalPrice %}
{% if displayRounded %}
    {% set total = order.price.rawTotal %}
    {% set decimals = order.itemRounding.decimals %}
{% endif %}
{% for shippingCost in order.deliveries %}
%shipping%: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}
{% endfor %}
%net%: {{ order.amountNet|currency(currencyIsoCode) }}
{% for calculatedTax in order.price.calculatedTaxes %}
%plusTax% {{ calculatedTax.taxRate }} %: {{ calculatedTax.tax|currency(currencyIsoCode) }}
{% endfor %}
%total%: {{ total|currency(currencyIsoCode, decimals=decimals) }}
{% if displayRounded %}
%roundedTotal%: {{ order.price.totalPrice|currency(currencyIsoCode, decimals=order.totalRounding.decimals) }}
{% endif %}

{% if order.transactions is defined and order.transactions is not empty %}
%payment%: {{ order.transactions.first.paymentMethod.translated.name }}
{% endif %}
{% if delivery %}
%shippingType%: {{ delivery.shippingMethod.translated.name }}
{% endif %}

{% set billingAddress = order.addresses.get(order.billingAddressId) %}
%billing%:
{% if billingAddress.company %}{{ billingAddress.company }}
{% endif %}{{ billingAddress.firstName }} {{ billingAddress.lastName }}
{{ billingAddress.street }}
{{ billingAddress.zipcode }} {{ billingAddress.city }}
{{ billingAddress.country.translated.name }}

{% if delivery %}
{% set shippingAddress = delivery.shippingOrderAddress %}
%shippingAddr%:
{% if shippingAddress.company %}{{ shippingAddress.company }}
{% endif %}{{ shippingAddress.firstName }} {{ shippingAddress.lastName }}
{{ shippingAddress.street }}
{{ shippingAddress.zipcode }} {{ shippingAddress.city }}
{{ shippingAddress.country.translated.name }}
{% endif %}

{% if order.orderCustomer.vatIds %}
%vatId%: {{ order.orderCustomer.vatIds|first }}
%vatNote%

{% endif %}
%statusNote%

{% if a11yDocuments is defined and a11yDocuments is not empty %}
%a11yIntro%
{% for a11y in a11yDocuments %}
- {{ rawUrl('frontend.account.order.single.document.a11y', { documentId: a11y.documentId, deepLinkCode: a11y.deepLinkCode, fileType: a11y.fileExtension }, shopUrl) }}
{% endfor %}
%a11yNote%

{% endif %}
{% if config('core.cart.showLegalGuaranteeNotice') %}
%guarantee%: {{ context.languageId|sw_legal_guarantee_notice_link }}

{% endif %}
%questions%

%signoff%
TWIG;

        $texts = array_map(static fn (string $text): string => str_replace('<br>', "\n", $text), $this->texts($sl));

        return strtr($template, $texts);
    }
}
