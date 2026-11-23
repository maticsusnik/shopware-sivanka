<?php declare(strict_types=1);

namespace OptiwebTheme\Core\Checkout\UpnQr;

/**
 * One UPN QR payment order (Slovenian "univerzalni plačilni nalog", ZBS standard): the data a
 * banking app pre-fills when the code is scanned.
 *
 * The payload is 19 fields, each ended by a line feed, followed by a 3-digit checksum — the
 * length of everything before it. The payer fields (IBAN, deposit/withdrawal flags, reference,
 * name, address), the payment date and the urgency flag are left empty: the payer is whoever
 * scans, and the app fills those in. Field order and lengths follow the layout used by the
 * DataLinx php-upn-qr-generator library.
 *
 * Every text is kept to its field's maximum length and to characters ISO-8859-2 can encode
 * (the QR is written in that charset), so a long company name or an exotic character never
 * breaks the code — it is shortened or dropped instead.
 */
final class UpnQr
{
    private const LEADING_STRING = 'UPNQR';

    private const NAME_LENGTH = 33;
    private const PURPOSE_LENGTH = 42;
    private const REFERENCE_LENGTH = 26;

    /** 11 digits in cents: 999.999.999,99. */
    private const MAX_AMOUNT_CENTS = 99_999_999_999;

    private function __construct(
        public readonly int $amountCents,
        public readonly string $purposeCode,
        public readonly string $purpose,
        public readonly \DateTimeInterface $dueDate,
        public readonly string $recipientIban,
        public readonly string $recipientReference,
        public readonly string $recipientName,
        public readonly string $recipientStreet,
        public readonly string $recipientCity,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when a value cannot go into a valid payment order
     */
    public static function create(
        int $amountCents,
        string $purposeCode,
        string $purpose,
        \DateTimeInterface $dueDate,
        string $recipientIban,
        string $recipientReference,
        string $recipientName,
        string $recipientStreet,
        string $recipientCity,
    ): self {
        if ($amountCents <= 0 || $amountCents > self::MAX_AMOUNT_CENTS) {
            throw new \InvalidArgumentException('UPN QR amount must be between 0,01 and 999.999.999,99.');
        }

        $purposeCode = strtoupper(trim($purposeCode));
        if (!preg_match('/^[A-Z]{4}$/', $purposeCode)) {
            throw new \InvalidArgumentException('UPN QR purpose code must be four letters, e.g. GDSV.');
        }

        $iban = self::normalizeIban($recipientIban);
        if ($iban === null) {
            throw new \InvalidArgumentException('UPN QR recipient IBAN is not a valid IBAN.');
        }

        $reference = strtoupper(preg_replace('/\s+/', '', $recipientReference) ?? '');
        if (!preg_match('/^(SI|RF)\d{2}[0-9A-Z-]*$/', $reference) || \strlen($reference) > self::REFERENCE_LENGTH) {
            throw new \InvalidArgumentException('UPN QR reference must start with SI or RF and two digits, at most 26 characters.');
        }

        $city = self::text($recipientCity, self::NAME_LENGTH);
        if ($city === '') {
            throw new \InvalidArgumentException('UPN QR recipient city is required.');
        }

        return new self(
            $amountCents,
            $purposeCode,
            self::text($purpose, self::PURPOSE_LENGTH),
            $dueDate,
            $iban,
            $reference,
            self::text($recipientName, self::NAME_LENGTH),
            self::text($recipientStreet, self::NAME_LENGTH),
            $city,
        );
    }

    /**
     * The text encoded in the QR code (UTF-8; the writer converts it to ISO-8859-2).
     */
    public function toPayload(): string
    {
        $fields = [
            self::LEADING_STRING,
            '', // payer IBAN
            '', // deposit (polog)
            '', // withdrawal (dvig)
            '', // payer reference
            '', // payer name
            '', // payer street
            '', // payer city
            str_pad((string) $this->amountCents, 11, '0', \STR_PAD_LEFT),
            '', // payment date
            '', // urgent
            $this->purposeCode,
            $this->purpose,
            $this->dueDate->format('d.m.Y'),
            $this->recipientIban,
            $this->recipientReference,
            $this->recipientName,
            $this->recipientStreet,
            $this->recipientCity,
        ];

        $payload = implode("\n", $fields) . "\n";

        return $payload . \sprintf('%03d', mb_strlen($payload));
    }

    /**
     * The IBAN as people read it, in groups of four: SI56 0123 4567 8901 234.
     */
    public function formattedIban(): string
    {
        return trim(chunk_split($this->recipientIban, 4, ' '));
    }

    /**
     * The reference as banks print it, model apart: SI00 10023.
     */
    public function formattedReference(): string
    {
        return substr($this->recipientReference, 0, 4) . ' ' . substr($this->recipientReference, 4);
    }

    /**
     * Uppercase, without spaces, checked against the IBAN mod-97 checksum — a typo in the
     * plugin config must not end up as money sent to the wrong account.
     */
    public static function normalizeIban(string $iban): ?string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return null;
        }

        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (\ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1 ? $iban : null;
    }

    /**
     * One line of text, limited to what ISO-8859-2 can encode and to $length characters.
     */
    private static function text(string $value, int $length): string
    {
        // Characters ISO-8859-2 has no code for are dropped (a round trip changes them), then
        // whitespace (line feeds would break the field layout) collapses to single spaces.
        $value = preg_replace_callback(
            '/./su',
            static fn (array $char) => mb_convert_encoding(mb_convert_encoding($char[0], 'ISO-8859-2', 'UTF-8'), 'UTF-8', 'ISO-8859-2') === $char[0] ? $char[0] : '',
            $value,
        ) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return trim(mb_substr($value, 0, $length));
    }
}
