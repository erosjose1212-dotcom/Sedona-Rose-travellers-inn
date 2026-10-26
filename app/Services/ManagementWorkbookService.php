<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ManagementWorkbookService
{
    /** @param array<string, mixed> $report */
    public function build(array $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $book->getProperties()->setCreator($report['property'])->setTitle($report['title']);
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        foreach ($report['sheets'] as $index => $table) {
            $sheet = $book->createSheet($index)->setTitle($table['name']);
            $last = Coordinate::stringFromColumnIndex(count($table['headers']));
            foreach ([$report['property'], $table['name'], $report['period']['start_date'].' to '.$report['period']['end_date'].' · '.($report['period']['calendar'] ? 'Calendar dates matching P&L' : ($report['period']['finance_day'] ? 'Daily Finance · 6:30 AM cutoff' : ucfirst($report['period']['shift']).' shifts · Asia/Manila')), 'Generated: '.$report['generated_at']] as $row => $text) {
                $sheet->mergeCells('A'.($row + 1).':'.$last.($row + 1));
                $sheet->setCellValueExplicit('A'.($row + 1), $text, DataType::TYPE_STRING);
            }
            $sheet->mergeCells('A5:'.$last.'5');
            $sheet->setCellValueExplicit('A5', $report['description'], DataType::TYPE_STRING);
            $sheet->getStyle('A5')->getAlignment()->setWrapText(true);
            $sheet->getRowDimension(5)->setRowHeight(30);
            foreach ($table['headers'] as $column => $header) {
                $letter = Coordinate::stringFromColumnIndex($column + 1);
                $sheet->setCellValueExplicit($letter.'6', $header, DataType::TYPE_STRING);
                $sheet->getColumnDimension($letter)->setWidth(count($table['headers']) === 3 ? [48, 24, 75][$column] : ($column === 8 ? 42 : 22));
            }
            foreach (array_values($table['rows']) as $row => $values) {
                $lines = 1;
                foreach ($values as $column => $value) {
                    $coordinate = Coordinate::stringFromColumnIndex($column + 1).($row + 7);
                    if ($value !== null) {
                        $header = $table['headers'][$column];
                        $isDate = in_array($header, ['Date', 'Business date', 'Expense date', 'Time In', 'Time Out', 'Expected checkout (current)', 'Transfer as of', 'Date & Time', 'Posted', 'Opened', 'Closed'], true);
                        if ($isDate && is_string($value)) {
                            $value = Date::dateTimeToExcel(new \DateTimeImmutable($value, new \DateTimeZone('Asia/Manila')));
                            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
                            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(in_array($header, ['Date', 'Business date', 'Expense date'], true) ? 'yyyy-mm-dd' : 'yyyy-mm-dd hh:mm:ss AM/PM');

                            continue;
                        }
                        $numeric = is_int($value) || is_float($value);
                        $sheet->setCellValueExplicit($coordinate, $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                        if ($numeric) {
                            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(is_int($value) ? '#,##0' : '#,##0.00');
                        } else {
                            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('@');
                        }
                        $width = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column + 1))->getWidth();
                        $lines = max($lines, (int) ceil(mb_strlen((string) $value) / ($width - 2)));
                    }
                }
                $sheet->getRowDimension($row + 7)->setRowHeight(max(28, $lines * 14 + 6));
            }
            $end = count($table['rows']) + 6;
            $footer = $end + 1;
            if ($table['totals'] !== []) {
                $sheet->setCellValueExplicit('A'.$footer, 'TOTAL', DataType::TYPE_STRING);
                foreach ($table['totals'] as $column) {
                    $letter = Coordinate::stringFromColumnIndex($column + 1);
                    $sheet->setCellValueExplicit($letter.$footer, $end < 7 ? 0 : '=SUM('.$letter.'7:'.$letter.$end.')', $end < 7 ? DataType::TYPE_NUMERIC : DataType::TYPE_FORMULA);
                    $sheet->getStyle($letter.$footer)->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $sheet->getStyle('A'.$footer.':'.$last.$footer)->getFont()->setBold(true);
            }
            $sheet->getStyle('A6:'.$last.max(6, $footer))->getAlignment()->setWrapText(true)->setVertical('top');
            $sheet->getStyle('A6:'.$last.'6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0E8ED');
            $sheet->getStyle('A6:'.$last.'6')->getFont()->setBold(true);
            $sheet->getStyle('A6:'.$last.max(6, $footer))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setARGB('FFD8DDE5');
            $sheet->getRowDimension(6)->setRowHeight(36);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->freezePane('A7');
            if ($end >= 7) {
                $sheet->setAutoFilter('A6:'.$last.$end);
            }
            foreach ($report['notes'] as $note) {
                $footer += 2;
                $sheet->mergeCells('A'.$footer.':'.$last.$footer);
                $sheet->setCellValueExplicit('A'.$footer, $note, DataType::TYPE_STRING);
                $sheet->getStyle('A'.$footer)->getAlignment()->setWrapText(true)->setVertical('top');
                $sheet->getRowDimension($footer)->setRowHeight(count($table['headers']) < 5 ? 65 : 36);
            }
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(count($table['headers']) > 10 ? PageSetup::PAPERSIZE_A3 : PageSetup::PAPERSIZE_A4)
                ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 6)->setPrintArea('A1:'.$last.$footer);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }
}
