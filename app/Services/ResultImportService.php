<?php
declare(strict_types=1);

namespace School\Services;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use School\Core\SchoolDatabase;

/**
 * End-to-end result import: scope validation, spreadsheet parsing, learner
 * matching, human review and application to the results register.
 */
final class ResultImportService
{
    private PDO $pdo;
    private StudentNameMatcher $matcher;

    public function __construct()
    {
        $this->pdo = SchoolDatabase::connection();
        $this->matcher = new StudentNameMatcher();
    }

    /**
     * Validate the exam/class/subject combination and (optionally) create the
     * subject assessment so a template and upload can share one scope.
     *
     * @return array<string,mixed>
     */
    public function resolveScope(int $examId, int $classId, int $subjectId, array $user, string $role, bool $createAssessment = true, float $maxMark = 100): array
    {
        if ($examId <= 0 || $classId <= 0 || $subjectId <= 0) {
            throw new RuntimeException('Choose an assessment, class and subject.');
        }
        $query = $this->pdo->prepare('SELECT e.id exam_id,e.name exam_name,e.type exam_type,e.workflow_status,t.id term_id,t.name term_name,t.academic_year_id year_id,y.name year_name,c.id class_id,c.name class_name,c.level class_level,s.id subject_id,s.code subject_code,s.name subject_name,s.level subject_level FROM examinations e JOIN terms t ON t.id=e.term_id JOIN academic_years y ON y.id=t.academic_year_id JOIN class_groups c ON c.id=:class AND c.academic_year_id=t.academic_year_id JOIN subjects s ON s.id=:subject AND s.active=1 WHERE e.id=:exam');
        $query->execute(['class' => $classId, 'subject' => $subjectId, 'exam' => $examId]);
        $scope = $query->fetch();
        if (!$scope) {
            throw new RuntimeException('The class and subject must belong to the assessment academic year.');
        }
        if ($createAssessment && !in_array($scope['workflow_status'], ['draft', 'open'], true)) {
            throw new RuntimeException('Results can only be entered or imported while the assessment period is draft or open.');
        }
        if ($scope['subject_level'] !== 'both' && $scope['subject_level'] !== $scope['class_level']) {
            throw new RuntimeException('That subject is not offered at the selected class level.');
        }
        if (in_array($role, ['class_teacher', 'subject_teacher'], true)) {
            $check = $this->pdo->prepare("SELECT 1 FROM teacher_assignments ta JOIN staff sf ON sf.id=ta.staff_id WHERE sf.user_id=:user AND sf.employment_status='active' AND ta.ended_at IS NULL AND ta.class_group_id=:class AND ta.academic_year_id=:year AND ta.subject_id=:subject LIMIT 1");
            $check->execute(['user' => (int) $user['id'], 'class' => $classId, 'year' => (int) $scope['year_id'], 'subject' => $subjectId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('You are not assigned to teach this class and subject.');
            }
        }

        $assessment = $this->pdo->prepare('SELECT id,max_mark,weight FROM assessments WHERE examination_id=:exam AND class_group_id=:class AND subject_id=:subject LIMIT 1');
        $assessment->execute(['exam' => $examId, 'class' => $classId, 'subject' => $subjectId]);
        $row = $assessment->fetch();
        if (!$row && $createAssessment) {
            if (!in_array($scope['workflow_status'], ['draft', 'open'], true)) {
                throw new RuntimeException('A new subject assessment can only be created while the assessment period is draft or open.');
            }
            if ($maxMark <= 0) $maxMark = 100;
            $insert = $this->pdo->prepare('INSERT INTO assessments (examination_id,subject_id,class_group_id,max_mark,weight) VALUES (:exam,:subject,:class,:max,1)');
            $insert->execute(['exam' => $examId, 'subject' => $subjectId, 'class' => $classId, 'max' => $maxMark]);
            $row = ['id' => (int) $this->pdo->lastInsertId(), 'max_mark' => $maxMark, 'weight' => 1];
        }
        if (!$row) {
            throw new RuntimeException('No subject assessment exists yet. Create the assessment first or download a template, which creates it.');
        }

        $scope['assessment_id'] = (int) $row['id'];
        $scope['max_mark'] = (float) $row['max_mark'];
        $scope['roster'] = $this->roster($classId, (int) $scope['year_id']);
        return $scope;
    }

    /** @return array<int,array{id:int,admission_number:string,full_name:string,class_name:string}> */
    public function roster(int $classId, int $yearId): array
    {
        $query = $this->pdo->prepare("SELECT s.id,s.admission_number,s.full_name,c.name class_name FROM students s JOIN enrollments en ON en.student_id=s.id AND en.status='active' AND en.academic_year_id=:year AND en.class_group_id=:class JOIN class_groups c ON c.id=en.class_group_id WHERE s.status='active' ORDER BY s.full_name");
        $query->execute(['year' => $yearId, 'class' => $classId]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'admission_number' => (string) $row['admission_number'],
            'full_name' => (string) $row['full_name'],
            'class_name' => (string) $row['class_name'],
        ], $query->fetchAll());
    }

    /**
     * Read an uploaded spreadsheet into raw rows.
     *
     * @return array<int,array{row_number:int,raw_admission:string,raw_name:string,raw_mark:string,raw_comment:string}>
     */
    public function readSpreadsheet(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('The uploaded file could not be read.');
        }
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $exception) {
            throw new RuntimeException('That file is not a readable Excel or CSV workbook. Save it as .xlsx and try again.', 0, $exception);
        }

        $sheet = $spreadsheet->getSheet(0);
        $matrix = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        if ($matrix === []) {
            throw new RuntimeException('The spreadsheet is empty.');
        }

        $headerIndex = null;
        $columns = ['admission' => 0, 'name' => 1, 'mark' => 2, 'comment' => 3];
        foreach (array_slice($matrix, 0, 12, true) as $index => $row) {
            $normalized = array_map(static fn($value): string => mb_strtolower(trim((string) $value)), $row);
            $filled = count(array_filter($normalized, static fn(string $value): bool => $value !== ''));
            if ($filled < 2) continue;
            $admissionColumn = $this->findColumn($normalized, ['admission number', 'admission', 'adm', 'reg no', 'registration']);
            $nameColumn = $this->findColumn($normalized, ['student name', 'name', 'learner', 'full name']);
            $markColumn = $this->findColumn($normalized, ['mark', 'score', 'marks', 'result']);
            if ($admissionColumn !== null || ($nameColumn !== null && $markColumn !== null)) {
                $headerIndex = $index;
                $columns = [
                    'admission' => $admissionColumn ?? 0,
                    'name' => $nameColumn ?? 1,
                    'mark' => $markColumn ?? 2,
                    'comment' => $this->findColumn($normalized, ['comment', 'remarks', 'teacher comment']) ?? 3,
                ];
                break;
            }
        }

        $rows = [];
        foreach ($matrix as $index => $row) {
            if ($headerIndex !== null && $index <= $headerIndex) continue;
            $admission = trim((string) ($row[$columns['admission']] ?? ''));
            $name = trim((string) ($row[$columns['name']] ?? ''));
            $mark = trim((string) ($row[$columns['mark']] ?? ''));
            $comment = trim((string) ($row[$columns['comment']] ?? ''));
            if ($admission === '' && $name === '' && $mark === '') continue;
            // Ignore repeated header rows in hand-edited workbooks.
            if (mb_strtolower($name) === 'student name' || mb_strtolower($admission) === 'admission number') continue;
            $rows[] = [
                'row_number' => $index + 1,
                'raw_admission' => $admission,
                'raw_name' => $name,
                'raw_mark' => $mark,
                'raw_comment' => $comment,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('No learner rows were found in the spreadsheet.');
        }
        return $rows;
    }

    /**
     * Create a review batch from parsed rows.
     *
     * @param array<string,mixed> $scope
     * @param array<int,array<string,mixed>> $rows
     */
    public function createBatch(array $scope, array $rows, string $filename, int $userId): int
    {
        if ($rows === []) {
            throw new RuntimeException('There are no rows to import.');
        }
        $maxMark = (float) $scope['max_mark'];
        $roster = $scope['roster'];
        $seen = [];

        return SchoolDatabase::transaction(function (PDO $pdo) use ($scope, $rows, $filename, $userId, $maxMark, $roster, &$seen): int {
            $insertBatch = $pdo->prepare("INSERT INTO result_import_batches (examination_id,assessment_id,class_group_id,subject_id,original_filename,uploaded_by,status,total_rows) VALUES (:exam,:assessment,:class,:subject,:filename,:user,'review',:total)");
            $insertBatch->execute([
                'exam' => (int) $scope['exam_id'], 'assessment' => (int) $scope['assessment_id'],
                'class' => (int) $scope['class_id'], 'subject' => (int) $scope['subject_id'],
                'filename' => mb_substr($filename, 0, 255), 'user' => $userId, 'total' => count($rows),
            ]);
            $batchId = (int) $pdo->lastInsertId();

            $insertRow = $pdo->prepare("INSERT INTO result_import_rows (batch_id,row_number,raw_name,raw_admission,raw_mark,mark,match_status,match_method,matched_student_id,suggested_student_id,confidence,candidates_json,error_message) VALUES (:batch,:row_number,:raw_name,:raw_admission,:raw_mark,:mark,:status,:method,:matched,:suggested,:confidence,:candidates,:error)");

            foreach ($rows as $row) {
                $rawName = trim((string) ($row['raw_name'] ?? ''));
                $rawAdmission = trim((string) ($row['raw_admission'] ?? ''));
                $rawMark = trim((string) ($row['raw_mark'] ?? ''));
                $rowNumber = (int) ($row['row_number'] ?? 0);

                $mark = $this->parseMark($rawMark);
                $status = 'error';
                $method = 'none';
                $matchedId = null;
                $suggestedId = null;
                $confidence = null;
                $candidates = [];
                $error = null;

                if ($rawName === '' && $rawAdmission === '') {
                    $error = 'Missing learner name and admission number.';
                } elseif ($mark === null) {
                    $error = $rawMark === '' ? 'No mark entered.' : 'The mark is not a valid number.';
                } elseif ($mark < 0 || $mark > $maxMark) {
                    $error = 'The mark must be between 0 and ' . rtrim(rtrim(number_format($maxMark, 2, '.', ''), '0'), '.') . '.';
                } else {
                    $match = $this->matcher->match($rawAdmission !== '' ? $rawAdmission : null, $rawName, $roster);
                    $status = $match['status'];
                    $method = $match['method'];
                    $matchedId = $match['student_id'];
                    $suggestedId = $match['suggested_student_id'];
                    $confidence = $match['confidence'];
                    $candidates = $match['candidates'];
                    $error = $match['message'];

                    if ($status === 'matched' && $matchedId !== null) {
                        if (isset($seen[$matchedId])) {
                            $status = 'error';
                            $method = 'none';
                            $error = 'This learner already appears in the batch (row ' . $seen[$matchedId] . ').';
                            $matchedId = null;
                        } else {
                            $seen[$matchedId] = $rowNumber;
                            $error = null;
                        }
                    }
                }

                $insertRow->execute([
                    'batch' => $batchId, 'row_number' => $rowNumber, 'raw_name' => mb_substr($rawName, 0, 180),
                    'raw_admission' => $rawAdmission !== '' ? mb_substr($rawAdmission, 0, 40) : null,
                    'raw_mark' => $rawMark !== '' ? mb_substr($rawMark, 0, 30) : null,
                    'mark' => $mark, 'status' => $status, 'method' => $method,
                    'matched' => $matchedId, 'suggested' => $suggestedId, 'confidence' => $confidence,
                    'candidates' => $candidates !== [] ? json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                    'error' => $error !== null ? mb_substr($error, 0, 500) : null,
                ]);
            }

            $this->refreshBatch($pdo, $batchId);
            (new AuditService())->record($userId, 'results.import.create', 'result_import_batches', $batchId, [
                'rows' => count($rows), 'filename' => $filename, 'scope' => [
                    'exam' => (int) $scope['exam_id'], 'class' => (int) $scope['class_id'], 'subject' => (int) $scope['subject_id'],
                ],
            ]);
            return $batchId;
        });
    }

    public function batch(int $batchId): ?array
    {
        $query = $this->pdo->prepare('SELECT b.*,e.name exam_name,e.workflow_status,t.name term_name,y.name year_name,c.name class_name,s.name subject_name,s.code subject_code,u.full_name uploader FROM result_import_batches b JOIN examinations e ON e.id=b.examination_id JOIN terms t ON t.id=e.term_id JOIN academic_years y ON y.id=t.academic_year_id JOIN class_groups c ON c.id=b.class_group_id JOIN subjects s ON s.id=b.subject_id JOIN users u ON u.id=b.uploaded_by WHERE b.id=:id');
        $query->execute(['id' => $batchId]);
        return $query->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(int $batchId): array
    {
        $query = $this->pdo->prepare('SELECT r.*, ms.full_name matched_name, ms.admission_number matched_admission, ss.full_name suggested_name, ss.admission_number suggested_admission FROM result_import_rows r LEFT JOIN students ms ON ms.id=r.matched_student_id LEFT JOIN students ss ON ss.id=r.suggested_student_id WHERE r.batch_id=:id ORDER BY r.row_number');
        $query->execute(['id' => $batchId]);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row['candidates'] = $row['candidates_json'] ? json_decode((string) $row['candidates_json'], true) : [];
        }
        return $rows;
    }

    /** @return array<string,int> */
    public function counts(int $batchId): array
    {
        $query = $this->pdo->prepare('SELECT match_status, COUNT(*) total FROM result_import_rows WHERE batch_id=:id GROUP BY match_status');
        $query->execute(['id' => $batchId]);
        $counts = ['matched' => 0, 'review' => 0, 'approved' => 0, 'rejected' => 0, 'applied' => 0, 'error' => 0];
        foreach ($query->fetchAll() as $row) $counts[$row['match_status']] = (int) $row['total'];
        return $counts;
    }

    public function approveRow(int $rowId, int $studentId, int $userId): void
    {
        SchoolDatabase::transaction(function (PDO $pdo) use ($rowId, $studentId, $userId): void {
            $row = $pdo->prepare('SELECT r.*,b.class_group_id,b.examination_id FROM result_import_rows r JOIN result_import_batches b ON b.id=r.batch_id WHERE r.id=:id FOR UPDATE');
            $row->execute(['id' => $rowId]);
            $current = $row->fetch();
            if (!$current) throw new RuntimeException('Import row not found.');
            if (in_array($current['match_status'], ['applied'], true)) throw new RuntimeException('This row has already been applied.');

            $student = $pdo->prepare("SELECT s.id,s.full_name,s.admission_number FROM students s JOIN enrollments en ON en.student_id=s.id AND en.status='active' AND en.class_group_id=:class WHERE s.id=:student AND s.status='active'");
            $student->execute(['class' => (int) $current['class_group_id'], 'student' => $studentId]);
            $learner = $student->fetch();
            if (!$learner) throw new RuntimeException('Choose an active learner from this class register.');

            $update = $pdo->prepare("UPDATE result_import_rows SET matched_student_id=:student, match_status='approved', match_method='manual', reviewed_by=:user, reviewed_at=UTC_TIMESTAMP(), error_message=NULL WHERE id=:id");
            $update->execute(['student' => $studentId, 'user' => $userId, 'id' => $rowId]);
            $this->refreshBatch($pdo, (int) $current['batch_id']);
            (new AuditService())->record($userId, 'results.import.approve', 'result_import_rows', $rowId, ['student' => $studentId, 'batch' => (int) $current['batch_id']]);
        });
    }

    public function rejectRow(int $rowId, int $userId): void
    {
        SchoolDatabase::transaction(function (PDO $pdo) use ($rowId, $userId): void {
            $row = $pdo->prepare('SELECT batch_id FROM result_import_rows WHERE id=:id FOR UPDATE');
            $row->execute(['id' => $rowId]);
            $batchId = (int) $row->fetchColumn();
            if (!$batchId) throw new RuntimeException('Import row not found.');
            $update = $pdo->prepare("UPDATE result_import_rows SET match_status='rejected', reviewed_by=:user, reviewed_at=UTC_TIMESTAMP() WHERE id=:id");
            $update->execute(['user' => $userId, 'id' => $rowId]);
            $this->refreshBatch($pdo, $batchId);
            (new AuditService())->record($userId, 'results.import.reject', 'result_import_rows', $rowId, ['batch' => $batchId]);
        });
    }

    public function reopenRow(int $rowId, int $userId): void
    {
        SchoolDatabase::transaction(function (PDO $pdo) use ($rowId, $userId): void {
            $row = $pdo->prepare('SELECT batch_id,match_method FROM result_import_rows WHERE id=:id FOR UPDATE');
            $row->execute(['id' => $rowId]);
            $current = $row->fetch();
            if (!$current) throw new RuntimeException('Import row not found.');
            $update = $pdo->prepare("UPDATE result_import_rows SET match_status='review', matched_student_id=NULL, reviewed_by=NULL, reviewed_at=NULL WHERE id=:id");
            $update->execute(['id' => $rowId]);
            $this->refreshBatch($pdo, (int) $current['batch_id']);
            (new AuditService())->record($userId, 'results.import.reopen', 'result_import_rows', $rowId, []);
        });
    }

    /**
     * Apply every matched/approved row to the results register.
     *
     * @return array{applied:int,skipped:int,errors:array<int,string>}
     */
    public function apply(int $batchId, int $userId): array
    {
        return SchoolDatabase::transaction(function (PDO $pdo) use ($batchId, $userId): array {
            $batchQuery = $pdo->prepare('SELECT b.*,a.max_mark FROM result_import_batches b JOIN assessments a ON a.id=b.assessment_id WHERE b.id=:id FOR UPDATE');
            $batchQuery->execute(['id' => $batchId]);
            $batch = $batchQuery->fetch();
            if (!$batch) throw new RuntimeException('Import batch not found.');
            if (in_array($batch['status'], ['applied', 'cancelled'], true)) throw new RuntimeException('This batch cannot be applied again.');
            if ((int) $batch['review_rows'] > 0) throw new RuntimeException('Approve or reject every review row before applying the batch.');
            if ((int) $batch['error_rows'] > 0) throw new RuntimeException('Correct or reject the error rows before applying the batch.');

            $rows = $pdo->prepare("SELECT * FROM result_import_rows WHERE batch_id=:id AND match_status IN ('matched','approved') ORDER BY row_number");
            $rows->execute(['id' => $batchId]);
            $maxMark = (float) $batch['max_mark'];
            $applied = 0;
            $skipped = 0;
            $errors = [];

            $find = $pdo->prepare('SELECT id,current_version,status FROM results WHERE assessment_id=:assessment AND student_id=:student FOR UPDATE');
            $insert = $pdo->prepare("INSERT INTO results (assessment_id,student_id,mark,status,current_version) VALUES (:assessment,:student,:mark,'submitted',1)");
            $update = $pdo->prepare("UPDATE results SET mark=:mark,status='submitted',current_version=:version WHERE id=:id");
            $version = $pdo->prepare('INSERT INTO result_versions (result_id,version,mark,changed_by,reason) VALUES (:result,:version,:mark,:user,:reason)');
            $markRow = $pdo->prepare("UPDATE result_import_rows SET match_status='applied', result_id=:result, reviewed_by=:user, reviewed_at=UTC_TIMESTAMP() WHERE id=:id");
            $skipRow = $pdo->prepare("UPDATE result_import_rows SET match_status='error', error_message=:error, reviewed_by=:user, reviewed_at=UTC_TIMESTAMP() WHERE id=:id");

            foreach ($rows->fetchAll() as $row) {
                $mark = $row['mark'] !== null ? (float) $row['mark'] : null;
                $studentId = (int) ($row['matched_student_id'] ?? 0);
                if ($mark === null || $studentId <= 0 || $mark < 0 || $mark > $maxMark) {
                    $reason = 'Row ' . (int) $row['row_number'] . ' has an invalid learner or mark.';
                    $errors[] = $reason;
                    $skipped++;
                    $skipRow->execute(['error' => mb_substr($reason, 0, 500), 'user' => $userId, 'id' => (int) $row['id']]);
                    continue;
                }
                $find->execute(['assessment' => (int) $batch['assessment_id'], 'student' => $studentId]);
                $existing = $find->fetch();
                if ($existing && $existing['status'] === 'published') {
                    $reason = 'Row ' . (int) $row['row_number'] . ': published results cannot be overwritten.';
                    $errors[] = $reason;
                    $skipped++;
                    $skipRow->execute(['error' => mb_substr($reason, 0, 500), 'user' => $userId, 'id' => (int) $row['id']]);
                    continue;
                }
                if ($existing) {
                    $next = (int) $existing['current_version'] + 1;
                    $update->execute(['mark' => $mark, 'version' => $next, 'id' => (int) $existing['id']]);
                    $resultId = (int) $existing['id'];
                } else {
                    $insert->execute(['assessment' => (int) $batch['assessment_id'], 'student' => $studentId, 'mark' => $mark]);
                    $resultId = (int) $pdo->lastInsertId();
                    $next = 1;
                }
                $version->execute([
                    'result' => $resultId, 'version' => $next, 'mark' => $mark, 'user' => $userId,
                    'reason' => 'Imported from ' . mb_substr((string) $batch['original_filename'], 0, 120),
                ]);
                $markRow->execute(['result' => $resultId, 'user' => $userId, 'id' => (int) $row['id']]);
                $applied++;
            }

            $updateBatch = $pdo->prepare("UPDATE result_import_batches SET status='applied', applied_rows=:applied, applied_at=UTC_TIMESTAMP() WHERE id=:id");
            $updateBatch->execute(['applied' => $applied, 'id' => $batchId]);
            (new AuditService())->record($userId, 'results.import.apply', 'result_import_batches', $batchId, ['applied' => $applied, 'skipped' => $skipped]);
            return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
        });
    }

    public function cancel(int $batchId, int $userId): void
    {
        $query = $this->pdo->prepare("UPDATE result_import_batches SET status='cancelled' WHERE id=:id AND status IN ('review','ready')");
        $query->execute(['id' => $batchId]);
        (new AuditService())->record($userId, 'results.import.cancel', 'result_import_batches', $batchId, []);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentBatches(array $user, string $role, int $limit = 12): array
    {
        $sql = 'SELECT b.id,b.original_filename,b.status,b.total_rows,b.matched_rows,b.review_rows,b.error_rows,b.applied_rows,b.created_at,e.name exam_name,c.name class_name,s.name subject_name,u.full_name uploader FROM result_import_batches b JOIN examinations e ON e.id=b.examination_id JOIN class_groups c ON c.id=b.class_group_id JOIN subjects s ON s.id=b.subject_id JOIN users u ON u.id=b.uploaded_by';
        $params = [];
        if (in_array($role, ['class_teacher', 'subject_teacher'], true)) {
            $sql .= ' WHERE b.uploaded_by=:user';
            $params['user'] = (int) $user['id'];
        }
        $sql .= ' ORDER BY b.id DESC LIMIT ' . max(1, min(100, $limit));
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        return $query->fetchAll();
    }

    private function refreshBatch(PDO $pdo, int $batchId): void
    {
        $counts = $pdo->prepare('SELECT match_status, COUNT(*) total FROM result_import_rows WHERE batch_id=:id GROUP BY match_status');
        $counts->execute(['id' => $batchId]);
        $map = ['matched' => 0, 'review' => 0, 'approved' => 0, 'rejected' => 0, 'applied' => 0, 'error' => 0];
        foreach ($counts->fetchAll() as $row) $map[$row['match_status']] = (int) $row['total'];
        $status = ($map['review'] === 0 && $map['error'] === 0) ? 'ready' : 'review';
        $update = $pdo->prepare('UPDATE result_import_batches SET matched_rows=:matched, review_rows=:review, error_rows=:errors, status=:status WHERE id=:id');
        $update->execute([
            'matched' => $map['matched'] + $map['approved'], 'review' => $map['review'],
            'errors' => $map['error'], 'status' => $status, 'id' => $batchId,
        ]);
    }

    private function parseMark(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') return null;
        $value = str_replace([' ', ','], ['', '.'], $value);
        if (!is_numeric($value)) return null;
        return round((float) $value, 2);
    }

    private function findColumn(array $headers, array $needles): ?int
    {
        foreach ($headers as $index => $header) {
            $header = trim($header);
            if ($header === '') continue;
            foreach ($needles as $needle) {
                if ($header === $needle) return (int) $index;
            }
        }
        foreach ($headers as $index => $header) {
            $header = trim($header);
            if ($header === '') continue;
            foreach ($needles as $needle) {
                if (str_starts_with($header, $needle)) return (int) $index;
            }
        }
        return null;
    }
}
