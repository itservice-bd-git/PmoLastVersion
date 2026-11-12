<?php

namespace App\Models\Concerns;

/** Project: contract value with VAT, margin %, and amounts as typed. */
trait ProjectMoney
{
    public function vatAmount(): ?float
    {
        return $this->contract_value !== null ? round((float) $this->contract_value * self::VAT_RATE, 2) : null;
    }

    /** มูลค่างานรวม VAT 7% - worked out, never stored (so it can never disagree with มูลค่างาน). */
    public function contractValueWithVat(): ?float
    {
        return $this->contract_value !== null ? round((float) $this->contract_value + $this->vatAmount(), 2) : null;
    }

    /** Margin as a share of the contract value (null when either is missing / the value is 0), e.g. 18.5. */
    public function marginPercent(): ?float
    {
        return $this->margin !== null && (float) $this->contract_value > 0 ? round((float) $this->margin / (float) $this->contract_value * 100, 1) : null;
    }

    /**
     * An amount typed by a person ("1,250,000", "1 250 000.50", "฿ 3,000") as a plain number string; '' / null stay null.
     * Anything else is passed through unchanged so validation (numeric) rejects it with a message.
     */
    public static function cleanAmount(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $plain = is_string($value) ? preg_replace('/[\s,฿]|บาท/u', '', $value) : $value;

        return is_numeric($plain) ? $plain : $value;
    }
}
