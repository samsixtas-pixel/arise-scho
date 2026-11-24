<?php
/**
 * MrzParser.php
 * -------------------------------------------------------
 * Parses ICAO 9303 TD3 passport machine-readable zones and
 * validates the check digits. This is a data-extraction
 * helper only — it does not authenticate a passport.
 * -------------------------------------------------------
 */

class MrzParser
{
    /** Parse two 44-character TD3 lines. */
    public function parse(string $text): array
    {
        $result = [
            'format'           => 'TD3',
            'valid'            => false,
            'passport_number'  => null,
            'surname'          => null,
            'given_names'      => null,
            'nationality'      => null,
            'issuing_country'  => null,
            'date_of_birth'    => null,
            'sex'              => null,
            'expiry_date'      => null,
            'personal_number'  => null,
            'checks'           => [],
            'errors'           => [],
            'confidence'       => 0.0,
        ];

        $text = strtoupper($text);
        $text = str_replace(["\r", ' '], ['', ''], $text);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn($line) => $line !== ''));

        $mrz = [];

        foreach ($lines as $line) {
            if (preg_match('/^[A-Z0-9<]{44}$/', $line)) {
                $mrz[] = $line;
            }
        }

        if (count($mrz) < 2) {
            $result['errors'][] = t('No valid two-line passport MRZ was found.');
            return $result;
        }

        [$line1, $line2] = array_slice($mrz, -2);

        if (!str_starts_with($line1, 'P')) {
            $result['errors'][] = t('The MRZ does not look like a passport (TD3) zone.');
            return $result;
        }

        $result['issuing_country'] = $this->country($line1, 2, 3);

        $nameBlock = substr($line1, 5);
        $parts = explode('<<', $nameBlock, 2);
        $result['surname'] = $this->cleanName($parts[0] ?? '');
        $result['given_names'] = $this->cleanName($parts[1] ?? '');

        $result['passport_number'] = $this->cleanNumber(substr($line2, 0, 9));
        $result['nationality'] = $this->country($line2, 10, 3);
        $result['date_of_birth'] = $this->mrzDate(substr($line2, 13, 6), true);
        $result['sex'] = match (substr($line2, 20, 1)) {
            'M' => 'male',
            'F' => 'female',
            default => null,
        };
        $result['expiry_date'] = $this->mrzDate(substr($line2, 21, 6), false);
        $result['personal_number'] = $this->cleanNumber(substr($line2, 28, 14));

        $checks = [
            'passport_number' => $this->checkDigit(substr($line2, 0, 9)) === substr($line2, 9, 1),
            'date_of_birth'   => $this->checkDigit(substr($line2, 13, 6)) === substr($line2, 19, 1),
            'expiry_date'     => $this->checkDigit(substr($line2, 21, 6)) === substr($line2, 27, 1),
            // TD3 personal-number field is 14 characters (offset 28–41)
            // followed by its check digit at 42.
            'personal_number' => $this->checkDigit(substr($line2, 28, 14)) === substr($line2, 42, 1),
            'composite'       => $this->checkDigit(
                substr($line2, 0, 10) . substr($line2, 13, 7) . substr($line2, 21, 7) . substr($line2, 28, 15)
            ) === substr($line2, 43, 1),
        ];

        $result['checks'] = $checks;

        foreach ($checks as $field => $ok) {
            if (!$ok) {
                $result['errors'][] = t('MRZ check digit failed: :field.', ['field' => str_replace('_', ' ', $field)]);
            }
        }

        $result['valid'] = !in_array(false, $checks, true)
            && $result['passport_number'] !== null
            && $result['surname'] !== null;

        $passed = count(array_filter($checks));
        $result['confidence'] = round($passed / max(1, count($checks)), 2);

        return $result;
    }

    /** ICAO 9303 check digit (weights 7,3,1). */
    public function checkDigit(string $value): string
    {
        $weights = [7, 3, 1];
        $sum     = 0;

        foreach (str_split($value) as $i => $char) {
            $sum += $this->charValue($char) * $weights[$i % 3];
        }

        return (string) ($sum % 10);
    }

    private function charValue(string $char): int
    {
        if ($char >= '0' && $char <= '9') {
            return (int) $char;
        }

        if ($char >= 'A' && $char <= 'Z') {
            return ord($char) - 55;
        }

        return 0; // '<'
    }

    private function cleanName(string $value): ?string
    {
        $value = trim(str_replace('<', ' ', $value));
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value !== '' ? $value : null;
    }

    private function cleanNumber(string $value): ?string
    {
        $value = trim(str_replace('<', '', $value));

        return $value !== '' ? $value : null;
    }

    private function country(string $line, int $offset, int $length): ?string
    {
        $value = substr($line, $offset, $length);

        if ($value === false || $value === '' || str_contains($value, '<')) {
            return null;
        }

        return $value;
    }

    private function mrzDate(string $value, bool $birth): ?string
    {
        if (!preg_match('/^([0-9]{2})([0-9]{2})([0-9]{2})$/', $value, $m)) {
            return null;
        }

        $yy = (int) $m[1];
        $mm = (int) $m[2];
        $dd = (int) $m[3];

        if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) {
            return null;
        }

        $currentYy = (int) date('y');
        $year = $birth
            ? ($yy > $currentYy + 5 ? 1900 + $yy : 2000 + $yy)
            : 2000 + $yy;

        if (!$birth && $year < (int) date('Y') - 20) {
            $year += 100;
        }

        return checkdate($mm, $dd, $year) ? sprintf('%04d-%02d-%02d', $year, $mm, $dd) : null;
    }
}
