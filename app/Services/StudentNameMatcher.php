<?php
declare(strict_types=1);

namespace School\Services;

/**
 * Deterministic learner-name matching for result imports.
 *
 * Only an exact admission number or an exact normalised name is auto-assigned.
 * Every near match is returned for human review together with ranked
 * suggestions and a confidence score.
 */
final class StudentNameMatcher
{
    private const TITLES = ['mr', 'mrs', 'miss', 'ms', 'dr', 'prof', 'professor', 'master', 'madam', 'sir'];

    public function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        // "Surname, Firstname" is common in paper registers.
        $value = str_replace([',', ';', '/', '\\', '.', '_', '-', "'", '’', '`'], ' ', $value);
        $value = str_replace('&', ' and ', $value);
        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($transliterated !== false && $transliterated !== '') {
                $value = $transliterated;
            }
        }
        $value = preg_replace('/[^a-z0-9 ]+/', ' ', (string) $value) ?? '';
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== '' && !in_array($token, self::TITLES, true)));
        return implode(' ', $tokens);
    }

    public function normalizeAdmission(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? '');
    }

    /**
     * @param array<int,array{id:int|string,admission_number:string,full_name:string,class_name?:string}> $roster
     * @return array{status:string,method:string,student_id:?int,suggested_student_id:?int,confidence:?float,candidates:array<int,array>,message:?string}
     */
    public function match(?string $admission, string $name, array $roster): array
    {
        $admissionKey = $this->normalizeAdmission((string) $admission);
        $nameKey = $this->normalize($name);

        if ($admissionKey !== '') {
            foreach ($roster as $student) {
                if ($this->normalizeAdmission((string) $student['admission_number']) === $admissionKey) {
                    return $this->result('matched', 'admission', (int) $student['id'], (int) $student['id'], 100.0, [$this->candidate($student, 100.0, 'Exact admission number')], null);
                }
            }
        }

        if ($nameKey !== '') {
            $exact = [];
            foreach ($roster as $student) {
                if ($this->normalize((string) $student['full_name']) === $nameKey) {
                    $exact[] = $student;
                }
            }
            if (count($exact) === 1) {
                return $this->result('matched', 'exact_name', (int) $exact[0]['id'], (int) $exact[0]['id'], 100.0, [$this->candidate($exact[0], 100.0, 'Exact name match')], null);
            }
            if (count($exact) > 1) {
                $candidates = array_map(fn(array $student): array => $this->candidate($student, 100.0, 'Duplicate exact name — choose the correct learner'), $exact);
                return $this->result('review', 'exact_name', null, (int) $exact[0]['id'], 100.0, $candidates, 'More than one learner has this exact name. Choose the correct record.');
            }
        }

        $scored = [];
        foreach ($roster as $student) {
            [$score, $reason] = $this->score($nameKey, $this->normalize((string) $student['full_name']));
            if ($score > 0) {
                $scored[] = ['student' => $student, 'score' => $score, 'reason' => $reason];
            }
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return strcmp((string) $a['student']['admission_number'], (string) $b['student']['admission_number']);
            }
            return $b['score'] <=> $a['score'];
        });
        $top = array_slice($scored, 0, 3);
        $candidates = array_map(fn(array $row): array => $this->candidate($row['student'], round($row['score'], 1), $row['reason']), $top);
        $best = $candidates[0] ?? null;

        if ($best === null || (float) $best['confidence'] < 45.0) {
            return $this->result('error', 'none', null, null, $best['confidence'] ?? null, $candidates, 'No close roster match. Check the spelling or add the learner to the class register.');
        }

        return $this->result('review', 'fuzzy', null, (int) $best['student_id'], (float) $best['confidence'], $candidates, 'Near match needs review. Approve a suggestion or reject the row.');
    }

    /** @return array{0:float,1:string} */
    private function score(string $needle, string $haystack): array
    {
        if ($needle === '' || $haystack === '') {
            return [0.0, ''];
        }
        if ($needle === $haystack) {
            return [100.0, 'Exact name match'];
        }

        $needleTokens = explode(' ', $needle);
        $haystackTokens = explode(' ', $haystack);
        sort($needleTokens);
        sort($haystackTokens);
        if ($needleTokens === $haystackTokens) {
            return [99.0, 'Same name words in a different order'];
        }

        similar_text($needle, $haystack, $similarPercent);
        $maxLength = max(strlen($needle), strlen($haystack));
        $levenshtein = $maxLength > 0 ? (1 - levenshtein($needle, $haystack) / $maxLength) * 100 : 0;

        $needleSet = array_unique(explode(' ', $needle));
        $haystackSet = array_unique(explode(' ', $haystack));
        $intersection = array_intersect($needleSet, $haystackSet);
        $union = array_unique(array_merge($needleSet, $haystackSet));
        $jaccard = count($union) > 0 ? count($intersection) * 100 / count($union) : 0;

        $score = max($similarPercent, $levenshtein, $jaccard);
        $reason = 'Name similarity';

        if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
            $score = max($score, 90.0);
            $reason = 'Name is contained in the roster name';
        }

        // Initial + surname, e.g. "J. Mwakyusa" against "John Mwakyusa".
        $needleInitials = array_map(static fn(string $token): string => $token[0] ?? '', $needleSet);
        $haystackInitials = array_map(static fn(string $token): string => $token[0] ?? '', $haystackSet);
        if ($needleInitials === $haystackInitials) {
            $score = max($score, 82.0);
            $reason = 'Matching initials';
        }

        return [min(100.0, $score), $reason];
    }

    private function candidate(array $student, float $confidence, string $reason): array
    {
        return [
            'student_id' => (int) $student['id'],
            'full_name' => (string) $student['full_name'],
            'admission_number' => (string) $student['admission_number'],
            'class_name' => (string) ($student['class_name'] ?? ''),
            'confidence' => round($confidence, 1),
            'reason' => $reason,
        ];
    }

    private function result(string $status, string $method, ?int $studentId, ?int $suggestedId, ?float $confidence, array $candidates, ?string $message): array
    {
        return [
            'status' => $status,
            'method' => $method,
            'student_id' => $studentId,
            'suggested_student_id' => $suggestedId,
            'confidence' => $confidence,
            'candidates' => $candidates,
            'message' => $message,
        ];
    }
}
