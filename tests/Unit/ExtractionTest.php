<?php

declare(strict_types=1);

use SolutionForest\AiKit\Extraction\ExcelExtractor;
use SolutionForest\AiKit\Extraction\WordExtractor;

function makeZip(string $name, array $entries): string
{
    $path = sys_get_temp_dir() . '/aikit_' . uniqid() . "_{$name}";
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $entryName => $contents) {
        $zip->addFromString($entryName, $contents);
    }
    $zip->close();

    return $path;
}

it('extracts text from a .docx (zipped XML, no dependency)', function (): void {
    $docx = makeZip('doc.docx', [
        'word/document.xml' => '<?xml version="1.0"?><w:document><w:body>'
            . '<w:p><w:r><w:t>Refund policy</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Refunds within 14 days.</w:t></w:r></w:p>'
            . '</w:body></w:document>',
    ]);

    $text = (new WordExtractor())->extract($docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    expect($text)->toContain('Refund policy')
        ->toContain('Refunds within 14 days.');
});

it('reports the mime types each Office extractor supports', function (): void {
    expect((new WordExtractor())->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBeTrue()
        ->and((new WordExtractor())->supports('application/pdf'))->toBeFalse()
        ->and((new ExcelExtractor())->supports('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBeTrue();
});

it('extracts shared strings from an .xlsx', function (): void {
    $xlsx = makeZip('book.xlsx', [
        'xl/sharedStrings.xml' => '<sst><si><t>Region</t></si><si><t>Revenue</t></si><si><t>APAC</t></si></sst>',
    ]);

    $text = (new ExcelExtractor())->extract($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($text)->toContain('Region')->toContain('Revenue')->toContain('APAC');
});
