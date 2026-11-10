<?php

namespace App\Support;

/**
 * Validación de identificadores fiscales españoles con su dígito de control:
 * DNI (8 cifras + letra), NIE (X/Y/Z + 7 cifras + letra) y CIF/NIF de
 * entidades (letra + 7 cifras + control). También IBAN (ISO 13616, módulo 97)
 * y código postal español.
 */
class SpanishTaxId
{
    private const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    public static function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/[\s\-.]/', '', (string) $value));
    }

    public static function isValid(?string $value): bool
    {
        $v = self::normalize($value);

        return self::isDni($v) || self::isNie($v) || self::isCif($v);
    }

    public static function isDni(string $v): bool
    {
        return (bool) preg_match('/^\d{8}[A-Z]$/', $v)
            && self::DNI_LETTERS[(int) substr($v, 0, 8) % 23] === $v[8];
    }

    public static function isNie(string $v): bool
    {
        if (! preg_match('/^[XYZ]\d{7}[A-Z]$/', $v)) {
            return false;
        }
        $number = strpos('XYZ', $v[0]).substr($v, 1, 7);

        return self::DNI_LETTERS[(int) $number % 23] === $v[8];
    }

    public static function isCif(string $v): bool
    {
        if (! preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $v)) {
            return false;
        }
        $digits = substr($v, 1, 7);
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $n = (int) $digits[$i];
            if ($i % 2 === 0) { // posiciones impares (1.ª, 3.ª...): se duplican
                $n *= 2;
                $n = intdiv($n, 10) + $n % 10;
            }
            $sum += $n;
        }
        $control = (10 - $sum % 10) % 10;
        $letter = 'JABCDEFGHI'[$control];
        $last = $v[8];

        // Unas entidades llevan letra de control, otras cifra; el resto, cualquiera.
        if (str_contains('PQRSNW', $v[0])) {
            return $last === $letter;
        }
        if (str_contains('ABEH', $v[0])) {
            return $last === (string) $control;
        }

        return $last === (string) $control || $last === $letter;
    }

    public static function isValidIban(?string $value): bool
    {
        $v = self::normalize($value);
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $v)) {
            return false;
        }
        if (str_starts_with($v, 'ES') && strlen($v) !== 24) {
            return false;
        }
        $rearranged = substr($v, 4).substr($v, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        // Módulo 97 por bloques para no desbordar enteros.
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /** Código postal español: 5 cifras y prefijo de provincia 01-52. */
    public static function isValidPostalCode(?string $value): bool
    {
        $v = trim((string) $value);

        return (bool) preg_match('/^\d{5}$/', $v) && (int) substr($v, 0, 2) >= 1 && (int) substr($v, 0, 2) <= 52;
    }
}
