<?php
declare(strict_types=1);

namespace School\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Builds the pre-filled Excel roster template that teachers download, fill in
 * and upload back. The learner rows come straight from the active class
 * register so names and admission numbers cannot drift.
 */
final class ResultTemplateBuilder
{
    /**
     * @param array{school_name?:string,year_name?:string,term_name?:string,exam_name?:string,
     *              class_name?:string,subject_name?:string,max_mark?:float,generated_by?:string,
     *              roster?:array<int,array{id:int|string,admission_number:string,full_name:string}>} $scope
     */
    public function build(array $scope): string
    {
        $directory = dirname(__DIR__, 2) . '/storage/school/templates';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            $directory = sys_get_temp_dir();
        }
        $path = $directory . '/results-template-' . bin2hex(random_bytes(8)) . '.xlsx';
        $this->write($scope, $path);
        return $path;
    }

    /** @param array<string,mixed> $scope */
    public function write(array $scope, string $path): void
    {
        $roster = array_values($scope['roster'] ?? []);
        $maxMark = (float) ($scope['max_mark'] ?? 100);
        if ($maxMark <= 0) $maxMark = 100;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('School Management')
            ->setTitle('Result entry template')
            ->setSubject(($scope['class_name'] ?? 'Class') . ' · ' . ($scope['subject_name'] ?? 'Subject'))
            ->setDescription('Pre-filled result sheet. Enter marks only; do not rename learners.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Results');

        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', (string) ($scope['school_name'] ?? 'School Management') . ' — Result entry sheet');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setARGB('FF063328');
        $sheet->getRowDimension(1)->setRowHeight(24);

        $sheet->mergeCells('A2:E2');
        $sheet->setCellValue('A2', trim(implode(' · ', array_filter([
            (string) ($scope['year_name'] ?? ''),
            (string) ($scope['term_name'] ?? ''),
            (string) ($scope['exam_name'] ?? ''),
            (string) ($scope['class_name'] ?? ''),
            (string) ($scope['subject_name'] ?? ''),
        ])), ' · '));
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setARGB('FF64716D');

        $sheet->mergeCells('A3:E3');
        $sheet->setCellValue('A3', 'Enter a mark between 0 and ' . rtrim(rtrim(number_format($maxMark, 2, '.', ''), '0'), '.') . ' for each learner. Leave a mark blank if the learner did not sit the assessment. Do not change names or admission numbers.');
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF8A5A00');
        $sheet->getStyle('A3')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(3)->setRowHeight(28);

        $headers = ['Admission number', 'Student name', 'Mark', 'Maximum mark', 'Teacher comment'];
        foreach ($headers as $index => $header) {
            $column = chr(65 + $index);
            $sheet->setCellValue($column . '5', $header);
        }
        $sheet->getStyle('A5:E5')->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A5:E5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF075C47');
        $sheet->getStyle('A5:E5')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(5)->setRowHeight(22);

        $row = 6;
        foreach ($roster as $student) {
            $sheet->setCellValue('A' . $row, (string) ($student['admission_number'] ?? ''));
            $sheet->setCellValue('B' . $row, (string) ($student['full_name'] ?? ''));
            $sheet->setCellValue('D' . $row, $maxMark);
            $sheet->setCellValue('E' . $row, '');
            $sheet->getStyle('C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF8E6');
            $row++;
        }
        $lastRow = max(6, $row - 1);

        $sheet->getStyle('A5:E' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFDCE5E1');
        $sheet->getStyle('A6:A' . $lastRow)->getFont()->setSize(9.5);
        $sheet->getStyle('B6:B' . $lastRow)->getFont()->setSize(9.5);
        $sheet->getStyle('C6:C' . $lastRow)->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('D6:D' . $lastRow)->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('D6:D' . $lastRow)->getFont()->getColor()->setARGB('FF64716D');
        $sheet->getStyle('E6:E' . $lastRow)->getAlignment()->setWrapText(true);

        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(34);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(38);

        $sheet->freezePane('A6');
        $sheet->setAutoFilter('A5:E' . $lastRow);

        if ($lastRow >= 6) {
            $validation = $sheet->getCell('C6')->getDataValidation();
            $validation->setType(DataValidation::TYPE_DECIMAL);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
            $validation->setFormula1('0');
            $validation->setFormula2((string) $maxMark);
            $validation->setAllowBlank(true);
            $validation->setShowInputMessage(true);
            $validation->setPromptTitle('Mark');
            $validation->setPrompt('Enter a mark from 0 to ' . $maxMark . '.');
            $validation->setShowErrorMessage(true);
            $validation->setErrorTitle('Invalid mark');
            $validation->setError('The mark must be between 0 and ' . $maxMark . '.');
            $sheet->setDataValidation('C6:C' . $lastRow, $validation);
        }

        $sheet->getStyle('A5:E5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C6:D' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $this->addInstructionsSheet($spreadsheet, $scope, $maxMark, count($roster));
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        try {
            $writer->save($path);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to build the Excel template: ' . $exception->getMessage(), 0, $exception);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function addInstructionsSheet(Spreadsheet $spreadsheet, array $scope, float $maxMark, int $count): void
    {
        /** @var Worksheet $sheet */
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Instructions');

        $lines = [
            ['How to use this result template'],
            [''],
            ['School', (string) ($scope['school_name'] ?? '')],
            ['Academic year', (string) ($scope['year_name'] ?? '')],
            ['Term', (string) ($scope['term_name'] ?? '')],
            ['Assessment', (string) ($scope['exam_name'] ?? '')],
            ['Class', (string) ($scope['class_name'] ?? '')],
            ['Subject', (string) ($scope['subject_name'] ?? '')],
            ['Maximum mark', $maxMark],
            ['Learners in template', $count],
            ['Prepared by', (string) ($scope['generated_by'] ?? '')],
            [''],
            ['Steps'],
            ['1. Open the Results sheet. Do not rename the sheet, learners or admission numbers.'],
            ['2. Enter each learner\'s mark in the Mark column. Blank means the learner did not sit the assessment.'],
            ['3. Add an optional comment in the Teacher comment column.'],
            ['4. Save the workbook as .xlsx and upload it on the result import page.'],
            [''],
            ['Matching rules'],
            ['• An exact admission number is assigned automatically.'],
            ['• An exact learner name is assigned automatically when it is unique.'],
            ['• Every near match is sent to review with suggestions; approve or reject each row.'],
            ['• Rows with no close roster match are flagged as errors and can be corrected before applying.'],
            [''],
            ['Do not add, delete or reorder learner rows. Extra rows are ignored.'],
        ];

        $row = 1;
        foreach ($lines as $line) {
            $sheet->setCellValue('A' . $row, $line[0] ?? '');
            if (isset($line[1])) $sheet->setCellValue('B' . $row, $line[1]);
            $row++;
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setARGB('FF063328');
        $sheet->getStyle('A13')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF075C47');
        $sheet->getStyle('A19')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF075C47');
        $sheet->getStyle('A12')->getFont()->setBold(true)->getColor()->setARGB('FF64716D');
        $sheet->getStyle('A2:B11')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('B8')->getNumberFormat()->setFormatCode('0.00');
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(78);
        $sheet->getStyle('B1:B40')->getAlignment()->setWrapText(true);
    }
}
