<?php declare(strict_types=1);

namespace OptiwebTheme\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Slovenian customer mails in the Sivanka layout of the order confirmation
 * (Migration1791800003): registration and e-mail confirmation, password mails, every
 * order / shipment / payment status change, the payment method change and the
 * document mails (invoice, delivery note, credit note, cancellation).
 *
 * The shop's language is Shopware's system language renamed to Slovenian, so these
 * were still the English install templates — most of them titled "New document for
 * your order". The payment received and payment reminder mails carry the full order
 * summary, laid out like the confirmation instead of the stock grey table.
 *
 * Dates are formatted in Europe/Ljubljana: status mails are rendered in admin / CLI
 * requests, which have no storefront timezone and printed UTC.
 *
 * Only translations still holding Shopware's stock template are replaced (all stock
 * templates share the same wrapper style), so a mail edited in the admin is kept.
 */
class Migration1791800005SivankaCustomerMails extends MigrationStep
{
    private const STOCK_MARKER = 'font-family:arial; font-size:12px;';

    private const STOCK_SUBJECTS = [
        'New document for your order',
        'Your sign-up with {{ salesChannel.translated.name }}',
        'Please confirm your sign-up with {{ salesChannel.translated.name }}',
        'Please confirm your email address at {{ salesChannel.translated.name }}',
        'The payment for your order has failed',
        'Payment method changed',
        'Password recovery',
        'Customer password changed',
    ];

    private const ORDER_URL = "rawUrl('frontend.account.order.single.page', { 'deepLinkCode': order.deepLinkCode }, shopUrl)";
    private const ACCOUNT_URL = "rawUrl('frontend.account.home.page', {}, shopUrl)";

    public function getCreationTimestamp(): int
    {
        return 1791800005;
    }

    public function update(Connection $connection): void
    {
        $mails = $this->mails();

        $rows = $connection->fetchAllAssociative(
            "SELECT t.mail_template_id, t.language_id, t.subject, t.content_html, y.technical_name
             FROM mail_template_translation t
             JOIN mail_template m ON m.id = t.mail_template_id
             JOIN mail_template_type y ON y.id = m.mail_template_type_id
             JOIN language l ON l.id = t.language_id
             JOIN locale lo ON lo.id = l.locale_id
             WHERE y.technical_name IN (:types) AND lo.code LIKE 'sl%'",
            ['types' => array_keys($mails)],
            ['types' => ArrayParameterType::STRING]
        );

        foreach ($rows as $row) {
            if (!str_contains((string) $row['content_html'], self::STOCK_MARKER)) {
                continue;
            }

            $mail = $mails[$row['technical_name']];

            $update = [
                'content_html'  => $this->html($mail),
                'content_plain' => $this->plain($mail),
                'updated_at'    => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ];

            if (\in_array(trim((string) $row['subject']), self::STOCK_SUBJECTS, true)) {
                $update['subject'] = $mail['subject'];
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

    /**
     * technical name => subject, heading, greeting name, intro (HTML), button, whether
     * it is an order mail (order box + status note) and whether it shows the summary.
     *
     * @return array<string, array{subject: string, heading: string, name: string, intro: string, button: ?array{0: string, 1: string}, order: bool, summary: bool, extra?: string}>
     */
    private function mails(): array
    {
        $order = fn (string $subject, string $heading, string $intro, bool $summary = false, string $button = 'Poglej naročilo', string $extra = ''): array => [
            'subject' => $subject,
            'heading' => $heading,
            'name'    => 'order.orderCustomer.firstName',
            'intro'   => $intro,
            'button'  => [$button, self::ORDER_URL],
            'order'   => true,
            'summary' => $summary,
            'extra'   => $extra,
        ];

        $customer = fn (string $subject, string $heading, string $name, string $intro, ?array $button, string $extra = ''): array => [
            'subject' => $subject,
            'heading' => $heading,
            'name'    => $name,
            'intro'   => $intro,
            'button'  => $button,
            'order'   => false,
            'summary' => false,
            'extra'   => $extra,
        ];

        $number = '{{ order.orderNumber }}';

        return [
            // Account
            'customer_register' => $customer(
                'Dobrodošli v spletni trgovini Šivanka',
                'Dobrodošli v Šivanki',
                'customer.firstName',
                'Hvala za registracijo. Vaš uporabniški račun je pripravljen, prijavite se z e-poštnim naslovom <strong>{{ customer.email }}</strong> in geslom, ki ste ga izbrali. Geslo lahko kadarkoli spremenite v svojem računu.',
                ['Moj račun', self::ACCOUNT_URL],
            ),
            'customer_register.double_opt_in' => $customer(
                'Potrdite registracijo v spletni trgovini Šivanka',
                'Potrdite registracijo',
                'customer.firstName',
                'Hvala za registracijo. Za aktivacijo računa potrdite svoj e-poštni naslov s klikom na gumb spodaj.',
                ['Potrdi registracijo', 'confirmUrl'],
                '<p style="margin:0 0 12px;color:#6B5A55;font-size:13px;">S potrditvijo se strinjate, da vam v okviru izpolnitve pogodbe pošiljamo tudi druga e-poštna sporočila.</p>',
            ),
            'guest_order.double_opt_in' => $customer(
                'Potrdite svoj e-poštni naslov',
                'Potrdite e-poštni naslov',
                'customer.firstName',
                'Za nadaljevanje nakupa potrdite svoj e-poštni naslov s klikom na gumb spodaj. Nato vas preusmerimo na blagajno, kjer lahko naročilo preverite in ga oddate.',
                ['Potrdi e-poštni naslov', 'confirmUrl'],
                '<p style="margin:0 0 12px;color:#6B5A55;font-size:13px;">S potrditvijo se strinjate, da vam v okviru izpolnitve pogodbe pošiljamo tudi druga e-poštna sporočila.</p>',
            ),
            'customer.recovery.request' => $customer(
                'Ponastavitev gesla',
                'Ponastavitev gesla',
                'customerRecovery.customer.firstName',
                'Prejeli smo zahtevo za ponastavitev gesla za vaš račun. Novo geslo nastavite s klikom na gumb spodaj. Povezava velja 2 uri.',
                ['Nastavi novo geslo', 'resetUrl'],
                '<p style="margin:0 0 12px;color:#6B5A55;font-size:13px;">Če ponastavitve niste zahtevali vi, to sporočilo preprosto prezrite – vaše geslo ostane nespremenjeno.</p>',
            ),
            'customer.password.changed' => $customer(
                'Vaše geslo je spremenjeno',
                'Geslo je spremenjeno',
                'customer.firstName',
                'Geslo za vaš račun v spletni trgovini Šivanka je bilo pravkar spremenjeno.',
                ['Moj račun', self::ACCOUNT_URL],
                '<p style="margin:0 0 12px;color:#6B5A55;font-size:13px;">Če gesla niste spremenili vi, nas nemudoma obvestite z odgovorom na to sporočilo.</p>',
            ),

            // Order status
            'order.state.open' => $order(
                "Naročilo $number je ponovno odprto",
                'Vaše naročilo je ponovno odprto',
                'Vaše naročilo je ponovno odprto in ga bomo obdelali v najkrajšem možnem času.',
            ),
            'order.state.in_progress' => $order(
                "Naročilo $number je v obdelavi",
                'Vaše naročilo je v obdelavi',
                'Vaše naročilo smo začeli pripravljati. Ko ga odpošljemo, vas obvestimo v ločenem sporočilu.',
            ),
            'order.state.completed' => $order(
                "Naročilo $number je zaključeno",
                'Vaše naročilo je zaključeno',
                'Vaše naročilo je zaključeno. Hvala za nakup – veselimo se vašega naslednjega obiska.',
            ),
            'order.state.cancelled' => $order(
                "Naročilo $number je preklicano",
                'Vaše naročilo je preklicano',
                'Vaše naročilo je bilo preklicano. Če ste ga že plačali, vam kupnino vrnemo in vas o tem obvestimo v ločenem sporočilu.',
            ),

            // Shipment status
            'order_delivery.state.shipped' => $order(
                "Naročilo $number je na poti",
                'Vaše naročilo je na poti',
                'Vaše naročilo smo odposlali in bo kmalu pri vas.',
                extra: $this->trackingCodes(),
            ),
            'order_delivery.state.shipped_partially' => $order(
                "Del naročila $number je na poti",
                'Del naročila je na poti',
                'Del vašega naročila smo že odposlali, preostanek sledi v kratkem.',
                extra: $this->trackingCodes(),
            ),
            'order_delivery.state.returned' => $order(
                "Vračilo naročila $number je prejeto",
                'Vračilo smo prejeli',
                'Prejeli smo vračilo vašega naročila. O vračilu kupnine vas obvestimo v ločenem sporočilu.',
            ),
            'order_delivery.state.returned_partially' => $order(
                "Delno vračilo naročila $number je prejeto",
                'Delno vračilo smo prejeli',
                'Prejeli smo vračilo dela vašega naročila. O vračilu kupnine vas obvestimo v ločenem sporočilu.',
            ),
            'order_delivery.state.cancelled' => $order(
                "Dostava naročila $number je preklicana",
                'Dostava je preklicana',
                'Dostava vašega naročila je bila preklicana.',
            ),

            // Payment status
            'order_transaction.state.open' => $order(
                "Plačilo za naročilo $number čaka na izvedbo",
                'Plačilo čaka na izvedbo',
                'Plačilo za vaše naročilo še čaka na izvedbo.',
            ),
            'order_transaction.state.unconfirmed' => $order(
                "Plačilo za naročilo $number čaka na potrditev",
                'Plačilo čaka na potrditev',
                'Vaše plačilo smo zabeležili, potrditev ponudnika plačil pa še čakamo. Ko bo potrjeno, vas obvestimo.',
            ),
            'order_transaction.state.authorized' => $order(
                "Plačilo za naročilo $number je odobreno",
                'Plačilo je odobreno',
                'Vaše plačilo je odobreno in bo izvedeno ob odpremi naročila.',
            ),
            'order_transaction.state.paid' => $order(
                "Plačilo za naročilo $number je prejeto",
                'Prejeli smo vaše plačilo',
                'Hvala, plačilo za vaše naročilo smo prejeli.',
                summary: true,
            ),
            'order_transaction.state.paid_partially' => $order(
                "Delno plačilo za naročilo $number je prejeto",
                'Prejeli smo del plačila',
                'Prejeli smo del plačila za vaše naročilo. Preostali znesek je razviden v vašem računu pod »Moja naročila«.',
            ),
            'order_transaction.state.reminded' => $order(
                "Opomnik za plačilo naročila $number",
                'Opomnik za plačilo',
                "Za vaše naročilo z dne {{ order.orderDateTime|format_datetime('medium', 'none', locale='sl-SI', timezone='Europe/Ljubljana') }} plačila z načinom »{{ order.transactions.first.paymentMethod.translated.name }}« še nismo prejeli. Plačilo lahko dokončate prek gumba spodaj.",
                summary: true,
                button: 'Dokončaj plačilo',
            ),
            'order_transaction.state.failed' => $order(
                "Plačilo za naročilo $number ni uspelo",
                'Plačilo ni uspelo',
                'Plačila za vaše naročilo ni bilo mogoče izvesti. Preverite podatke za plačilo ali v svojem računu izberite drug način plačila.',
                button: 'Dokončaj plačilo',
            ),
            'order_transaction.state.cancelled' => $order(
                "Plačilo za naročilo $number je preklicano",
                'Plačilo je preklicano',
                'Plačilo za vaše naročilo je bilo preklicano. Če želite naročilo vseeno prejeti, lahko v svojem računu izberete drug način plačila.',
            ),
            'order_transaction.state.refunded' => $order(
                "Kupnina za naročilo $number je vrnjena",
                'Kupnino smo vrnili',
                'Kupnino za vaše naročilo smo vrnili. Sredstva bodo na vašem računu vidna v nekaj delovnih dneh, odvisno od banke oziroma ponudnika plačil.',
            ),
            'order_transaction.state.refunded_partially' => $order(
                "Del kupnine za naročilo $number je vrnjen",
                'Del kupnine smo vrnili',
                'Del kupnine za vaše naročilo smo vrnili. Sredstva bodo na vašem računu vidna v nekaj delovnih dneh, odvisno od banke oziroma ponudnika plačil.',
            ),
            'order_transaction.state.chargeback' => $order(
                "Plačilo za naročilo $number je bilo vrnjeno",
                'Vaša banka je vrnila plačilo',
                'Vaša banka oziroma ponudnik plačil je plačilo za vaše naročilo vrnil. Za uskladitev nam prosimo odgovorite na to sporočilo.',
            ),
            'order.payment_method.changed' => $order(
                "Način plačila za naročilo $number je spremenjen",
                'Način plačila je spremenjen',
                'Potrjujemo, da je bil način plačila za vaše naročilo spremenjen. Nov način plačila: <strong>{{ order.transactions.last.paymentMethod.translated.name }}</strong>.',
            ),

            // Documents sent from the admin (the document is attached)
            'invoice_mail' => $order(
                "Račun za naročilo $number",
                'Račun za vaše naročilo',
                'V prilogi vam pošiljamo račun za vaše naročilo.',
            ),
            'delivery_mail' => $order(
                "Dobavnica za naročilo $number",
                'Dobavnica za vaše naročilo',
                'V prilogi vam pošiljamo dobavnico za vaše naročilo.',
            ),
            'credit_note_mail' => $order(
                "Dobropis za naročilo $number",
                'Dobropis za vaše naročilo',
                'V prilogi vam pošiljamo dobropis za vaše naročilo.',
            ),
            'cancellation_mail' => $order(
                "Storno računa za naročilo $number",
                'Storno računa',
                'V prilogi vam pošiljamo storno računa za vaše naročilo.',
            ),
        ];
    }

    private function trackingCodes(): string
    {
        return <<<'TWIG'
{% set trackedDelivery = order.deliveries.first %}
{% if trackedDelivery and trackedDelivery.trackingCodes is not empty %}
<p style="margin:0 0 24px;"><strong>Številka za sledenje:</strong>
{% for code in trackedDelivery.trackingCodes %}{% if trackedDelivery.shippingMethod.translated.trackingUrl %}<a href="{{ trackedDelivery.shippingMethod.translated.trackingUrl|format(code) }}" style="color:#8E2247;">{{ code }}</a>{% else %}{{ code }}{% endif %}{% if not loop.last %}, {% endif %}{% endfor %}</p>
{% endif %}
TWIG;
    }

    /** @param array{subject: string, heading: string, name: string, intro: string, button: ?array{0: string, 1: string}, order: bool, summary: bool, extra?: string} $mail */
    private function html(array $mail): string
    {
        $html = <<<'TWIG'
{% set shopUrl = 'https://www.sivanka.com' %}
{% if salesChannel is defined and salesChannel and salesChannel.domains is not empty %}
    {% set shopUrl = (salesChannel.domains|first).url %}
{% endif %}
{% set label = 'margin:0 0 4px;font-weight:bold;color:#5C1229;' %}
{% set cell = 'padding:12px 0;border-bottom:1px solid #EADFD1;font-size:14px;line-height:1.5;vertical-align:top;' %}
{% set muted = 'color:#6B5A55;font-size:13px;' %}
<h1 style="margin:0 0 16px;font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:1.3;font-weight:normal;color:#5C1229;">%heading%</h1>
<p style="margin:0 0 12px;">Pozdravljeni{% if %name% is defined and %name% %}, {{ %name% }}{% endif %}!</p>
<p style="margin:0 0 24px;">%intro%</p>
%extra%
TWIG;

        if ($mail['order']) {
            $html .= "\n" . $this->orderBox($mail['button']);
        } elseif ($mail['button'] !== null) {
            $html .= "\n" . $this->button($mail['button'], 'margin:0 0 24px;');
            $html .= "\n" . '<p style="margin:0 0 24px;{{ muted }}">Če gumb ne deluje, kopirajte to povezavo v brskalnik:<br><a href="{{ ' . $mail['button'][1] . ' }}" style="color:#8E2247;word-break:break-all;">{{ ' . $mail['button'][1] . ' }}</a></p>';
        }

        if ($mail['summary']) {
            $html .= "\n" . $this->summaryHtml();
        }

        if ($mail['order']) {
            $html .= "\n" . <<<'TWIG'
<p style="margin:0 0 12px;{{ muted }}">Stanje naročila lahko kadarkoli preverite v svojem računu pod »Moja naročila« ali prek gumba zgoraj. Pri nakupu brez registracije za dostop uporabite e-poštni naslov in poštno številko naslova za račun.</p>

{% if a11yDocuments is defined and a11yDocuments is not empty %}
<p style="margin:0 0 6px;{{ muted }}">Za boljšo dostopnost so dokumenti na voljo tudi v obliki HTML:</p>
<ul style="margin:0 0 6px;padding-left:18px;{{ muted }}">
{% for a11y in a11yDocuments %}
    {% set documentLink = rawUrl('frontend.account.order.single.document.a11y', { documentId: a11y.documentId, deepLinkCode: a11y.deepLinkCode, fileType: a11y.fileExtension }, shopUrl) %}
<li><a href="{{ documentLink }}" target="_blank" style="color:#8E2247;">{{ documentLink }}</a></li>
{% endfor %}
</ul>
<p style="margin:0 0 12px;{{ muted }}">Zaradi varstva podatkov je za ogled potrebna prijava; pri nakupu brez registracije uporabite e-poštni naslov in poštno številko naslova za račun.</p>
{% endif %}
TWIG;
        }

        $html .= "\n" . <<<'TWIG'
<p style="margin:16px 0;">Če imate vprašanja, nam preprosto odgovorite na to sporočilo.</p>
<p style="margin:0;">Lep pozdrav,<br>Trgovina ŠIVANKA</p>
TWIG;

        return strtr($html, [
            '%heading%' => $mail['heading'],
            '%name%'    => $mail['name'],
            '%intro%'   => $mail['intro'],
            '%extra%'   => $mail['extra'] ?? '',
        ]);
    }

    /** Order number, date and the order / payment button, as in the confirmation. */
    private function orderBox(array $button): string
    {
        return strtr(<<<'TWIG'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;background-color:#FDF8F2;border:1px solid #EADFD1;border-radius:6px;">
<tr>
<td width="50%" style="width:50%;padding:16px 20px 12px;font-size:14px;line-height:1.5;vertical-align:top;">
<span style="{{ muted }}">Številka naročila</span><br><strong style="font-size:16px;">{{ order.orderNumber }}</strong>
</td>
<td width="50%" style="width:50%;padding:16px 20px 12px;font-size:14px;line-height:1.5;vertical-align:top;">
<span style="{{ muted }}">Datum naročila</span><br>{{ order.orderDateTime|format_datetime('medium', 'short', locale='sl-SI', timezone='Europe/Ljubljana') }}
</td>
</tr>
<tr>
<td colspan="2" style="padding:0 20px 16px;">
%button%
</td>
</tr>
</table>
TWIG, ['%button%' => $this->button($button, '')]);
    }

    private function button(array $button, string $margin): string
    {
        [$text, $url] = $button;

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="' . $margin . '"><tr>'
            . '<td style="border-radius:4px;background-color:#8E2247;"><a href="{{ ' . $url . ' }}" style="display:inline-block;padding:10px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;color:#FFFFFF;text-decoration:none;white-space:nowrap;">' . $text . '</a></td>'
            . '</tr></table>';
    }

    /** Products, totals, payment / shipping method and addresses — the confirmation's layout. */
    private function summaryHtml(): string
    {
        return <<<'TWIG'
{% set currencyIsoCode = order.currency.isoCode %}
{% set delivery = order.deliveries.first %}
<p style="{{ label }}">Izdelki</p>
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
{% if nestedItem.payload.productNumber is defined %}<br><span style="{{ muted }}">Šifra: {{ nestedItem.payload.productNumber }}</span>{% endif %}
{% if nestedItem.payload.options is defined and nestedItem.payload.options|length >= 1 %}
<br><span style="{{ muted }}">{% for option in nestedItem.payload.options %}{{ option.group }}: {{ option.option }}{% if not loop.last %} &middot; {% endif %}{% endfor %}</span>
{% endif %}
{% if nestedItem.payload.features is defined and nestedItem.payload.features|length >= 1 %}
    {% set referencePriceFeatures = nestedItem.payload.features|filter(feature => feature.type == 'referencePrice') %}
    {% if referencePriceFeatures|length >= 1 %}
        {% set referencePriceFeature = referencePriceFeatures|first %}
<br><span style="{{ muted }}">{{ referencePriceFeature.value.purchaseUnit }} {{ referencePriceFeature.value.unitName }} ({{ referencePriceFeature.value.price|currency(currencyIsoCode) }} za {{ referencePriceFeature.value.referenceUnit }} {{ referencePriceFeature.value.unitName }})</span>
    {% endif %}
{% endif %}
<br><span style="{{ muted }}">{{ nestedItem.quantity }} &times; {{ nestedItem.unitPrice|currency(currencyIsoCode) }}</span>
</td>
<td align="right" style="{{ cell }}padding-left:12px;white-space:nowrap;"><strong>{{ nestedItem.totalPrice|currency(currencyIsoCode) }}</strong></td>
</tr>
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
<tr><td style="padding:3px 0;color:#6B5A55;">Dostava</td><td align="right" style="padding:3px 0;">{{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}</td></tr>
{% endfor %}
<tr><td style="padding:3px 0;color:#6B5A55;">Skupaj brez DDV</td><td align="right" style="padding:3px 0;">{{ order.amountNet|currency(currencyIsoCode) }}</td></tr>
{% for calculatedTax in order.price.calculatedTaxes %}
<tr><td style="padding:3px 0;color:#6B5A55;">DDV {{ calculatedTax.taxRate }} %</td><td align="right" style="padding:3px 0;">{{ calculatedTax.tax|currency(currencyIsoCode) }}</td></tr>
{% endfor %}
<tr><td style="padding:10px 0 0;border-top:1px solid #EADFD1;font-weight:bold;font-size:16px;color:#5C1229;">Skupaj</td><td align="right" style="padding:10px 0 0;border-top:1px solid #EADFD1;font-weight:bold;font-size:16px;color:#5C1229;">{{ total|currency(currencyIsoCode, decimals=decimals) }}</td></tr>
{% if displayRounded %}
<tr><td style="padding:3px 0;font-weight:bold;">Zaokroženo</td><td align="right" style="padding:3px 0;font-weight:bold;">{{ order.price.totalPrice|currency(currencyIsoCode, decimals=order.totalRounding.decimals) }}</td></tr>
{% endif %}
</table>

{% set billingAddress = order.addresses.get(order.billingAddressId) %}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;font-size:14px;line-height:1.5;">
<tr>
<td width="50%" style="width:50%;padding:0 12px 20px 0;vertical-align:top;">
{% if order.transactions is defined and order.transactions is not empty %}
<p style="{{ label }}">Način plačila</p>
<p style="margin:0;">{{ order.transactions.first.paymentMethod.translated.name }}{% if order.transactions.first.paymentMethod.translated.description %}<br><span style="{{ muted }}">{{ order.transactions.first.paymentMethod.translated.description }}</span>{% endif %}</p>
{% endif %}
</td>
<td width="50%" style="width:50%;padding:0 0 20px 12px;vertical-align:top;">
{% if delivery %}
<p style="{{ label }}">Način dostave</p>
<p style="margin:0;">{{ delivery.shippingMethod.translated.name }}{% if delivery.shippingMethod.translated.description %}<br><span style="{{ muted }}">{{ delivery.shippingMethod.translated.description }}</span>{% endif %}</p>
{% endif %}
</td>
</tr>
<tr>
<td width="50%" style="width:50%;padding:0 12px 0 0;vertical-align:top;">
{% if billingAddress %}
<p style="{{ label }}">Naslov za račun</p>
<p style="margin:0;">{% if billingAddress.company %}{{ billingAddress.company }}<br>{% endif %}{{ billingAddress.firstName }} {{ billingAddress.lastName }}<br>{{ billingAddress.street }}<br>{{ billingAddress.zipcode }} {{ billingAddress.city }}<br>{{ billingAddress.country.translated.name }}</p>
{% endif %}
</td>
<td width="50%" style="width:50%;padding:0 0 0 12px;vertical-align:top;">
{% if delivery %}
{% set shippingAddress = delivery.shippingOrderAddress %}
<p style="{{ label }}">Naslov za dostavo</p>
<p style="margin:0;">{% if shippingAddress.company %}{{ shippingAddress.company }}<br>{% endif %}{{ shippingAddress.firstName }} {{ shippingAddress.lastName }}<br>{{ shippingAddress.street }}<br>{{ shippingAddress.zipcode }} {{ shippingAddress.city }}<br>{{ shippingAddress.country.translated.name }}</p>
{% endif %}
</td>
</tr>
</table>
TWIG;
    }

    /** @param array{subject: string, heading: string, name: string, intro: string, button: ?array{0: string, 1: string}, order: bool, summary: bool, extra?: string} $mail */
    private function plain(array $mail): string
    {
        $text = static fn (string $html): string => trim(html_entity_decode(strip_tags(str_replace('<br>', "\n", $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

        $plain = <<<'TWIG'
{% set shopUrl = 'https://www.sivanka.com' %}
{% if salesChannel is defined and salesChannel and salesChannel.domains is not empty %}
    {% set shopUrl = (salesChannel.domains|first).url %}
{% endif %}
%heading%

Pozdravljeni{% if %name% is defined and %name% %}, {{ %name% }}{% endif %}!

%intro%

TWIG;

        if ($mail['order']) {
            $plain .= <<<'TWIG'
Številka naročila: {{ order.orderNumber }}
Datum naročila: {{ order.orderDateTime|format_datetime('medium', 'short', locale='sl-SI', timezone='Europe/Ljubljana') }}
%buttonText%: {{ %buttonUrl% }}

TWIG;
        } elseif ($mail['button'] !== null) {
            $plain .= "%buttonText%: {{ %buttonUrl% }}\n\n";
        }

        if (($mail['extra'] ?? '') !== '' && !str_contains($mail['extra'], 'trackedDelivery')) {
            $plain .= $text($mail['extra']) . "\n\n";
        }

        if (str_contains($mail['extra'] ?? '', 'trackedDelivery')) {
            $plain .= <<<'TWIG'
{% set trackedDelivery = order.deliveries.first %}
{% if trackedDelivery and trackedDelivery.trackingCodes is not empty %}
Številka za sledenje: {{ trackedDelivery.trackingCodes|join(', ') }}

{% endif %}
TWIG;
        }

        if ($mail['summary']) {
            $plain .= <<<'TWIG'
{% set currencyIsoCode = order.currency.isoCode %}
{% set delivery = order.deliveries.first %}
Izdelki:{{ "\n" }}
{%- for lineItem in order.nestedLineItems -%}
- {{ lineItem.label|u.wordwrap(80) }}{% if lineItem.payload.productNumber is defined %} (Šifra {{ lineItem.payload.productNumber }}){% endif %}{{ "\n" }}  {{ lineItem.quantity }} x {{ lineItem.unitPrice|currency(currencyIsoCode) }} = {{ lineItem.totalPrice|currency(currencyIsoCode) }}{{ "\n" }}
{%- endfor %}

{% for shippingCost in order.deliveries %}
Dostava: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}
{% endfor %}
Skupaj brez DDV: {{ order.amountNet|currency(currencyIsoCode) }}
{% for calculatedTax in order.price.calculatedTaxes %}
DDV {{ calculatedTax.taxRate }} %: {{ calculatedTax.tax|currency(currencyIsoCode) }}
{% endfor %}
Skupaj: {{ order.price.totalPrice|currency(currencyIsoCode) }}

{% if order.transactions is defined and order.transactions is not empty %}
Način plačila: {{ order.transactions.first.paymentMethod.translated.name }}
{% endif %}
{% if delivery %}
Način dostave: {{ delivery.shippingMethod.translated.name }}
{% endif %}

TWIG;
        }

        if ($mail['order']) {
            $plain .= "Stanje naročila lahko kadarkoli preverite v svojem računu pod »Moja naročila«. Pri nakupu brez registracije za dostop uporabite e-poštni naslov in poštno številko naslova za račun.\n\n";
        }

        $plain .= "Če imate vprašanja, nam preprosto odgovorite na to sporočilo.\n\nLep pozdrav,\nTrgovina ŠIVANKA";

        return strtr($plain, [
            '%heading%'    => $mail['heading'],
            '%name%'       => $mail['name'],
            '%intro%'      => $text($mail['intro']),
            '%buttonText%' => $mail['button'][0] ?? '',
            '%buttonUrl%'  => $mail['button'][1] ?? '',
        ]);
    }
}
