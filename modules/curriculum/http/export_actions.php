<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumExportService.php';

$action = curriculum_http_action();
$service = new CurriculumExportService($conn);
require_http_method(['GET', 'POST']);
$requireExportable = static function (array $input) use ($conn): void {
    if (!CurriculumReadScope::exportAllowed($conn, $input['curriculum_version_id'] ?? null)) {
        json_response(false, 'Your role cannot export a curriculum version in this review state.', [], 403);
    }
};

try {
    switch ($action) {
        case 'program_options':
            curriculum_require_read('curriculum_export.view');
            json_response(true, 'Academic program options loaded.', $service->programOptions($_GET));

        case 'curriculum_options':
            curriculum_require_read('curriculum_export.view');
            json_response(true, 'Curriculum options loaded.', $service->curriculumOptions($_GET));

        case 'version_options':
            curriculum_require_read('curriculum_export.view');
            json_response(true, 'Curriculum version options loaded.', $service->versionOptions($_GET));

        case 'scope_options':
            curriculum_require_read('curriculum_export.view');
            $requireExportable($_GET);
            json_response(true, 'Curriculum export scope options loaded.', $service->scopeOptions($_GET));

        case 'preview':
            curriculum_require_read('curriculum_export.view');
            $requireExportable($_GET);
            json_response(true, 'Curriculum export preview loaded.', $service->dataset($_GET));

        case 'download_csv':
            curriculum_require_mutation('curriculum_export.view');
            $requireExportable($_POST);
            // Optional password protection (AES-256 ZIP). The password exists only in this request; it is never logged, audited or stored.
            $protect = ($_POST['protect'] ?? '') === '1';
            $password = $protect ? CurriculumExportService::validateExportPassword($_POST['export_password'] ?? null, $_POST['export_password_confirm'] ?? null) : null;
            unset($_POST['export_password'], $_POST['export_password_confirm']);
            $dataset = $service->dataset($_POST);
            if ((int)$dataset['summary']['total_subjects'] === 0) {
                throw new DomainValidationException('No mapped subjects were found for the selected export scope.');
            }

            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('Unable to prepare CSV output.');
            }
            try {
                $service->writeCsv($stream, $dataset);
                if ($protect) {
                    rewind($stream);
                    $zipBytes = $service->protectedZip((string)stream_get_contents($stream), $service->csvFilename($dataset), (string)$password);
                    $password = null;
                    $service->recordProtectedGeneration($dataset);
                    fclose($stream);
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $service->zipFilename($dataset) . '"');
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: private, no-store, no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    header('Content-Length: ' . strlen($zipBytes));
                    echo $zipBytes;
                    exit;
                }
                // Record only after the CSV has been built successfully. A failed
                // generation must not leave a misleading success audit event.
                $service->recordCsvGeneration($dataset);
                $stats = fstat($stream);
                rewind($stream);

                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $service->csvFilename($dataset) . '"');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store, no-cache, must-revalidate');
                header('Pragma: no-cache');
                if (is_array($stats) && isset($stats['size'])) {
                    header('Content-Length: ' . (int)$stats['size']);
                }
                fpassthru($stream);
                fclose($stream);
                exit;
            } catch (Throwable $error) {
                fclose($stream);
                throw $error;
            }

        case 'download_pdf':
            // Same gate, same password rules and same dataset as the CSV. The PDF is plain unless protection is on; then it goes
            // inside the AES-256 ZIP (the password is used once and never logged, audited or stored). POST + CSRF only.
            curriculum_require_mutation('curriculum_export.view');
            $requireExportable($_POST);
            $protect = ($_POST['protect'] ?? '') === '1';
            $password = $protect ? CurriculumExportService::validateExportPassword($_POST['export_password'] ?? null, $_POST['export_password_confirm'] ?? null) : null;
            unset($_POST['export_password'], $_POST['export_password_confirm']);
            $dataset = $service->dataset($_POST);
            if ((int)$dataset['summary']['total_subjects'] === 0) {
                throw new DomainValidationException('No mapped subjects were found for the selected export scope.');
            }
            $pdfBytes = $service->pdf($dataset);
            if ($protect) {
                $body = $service->protectedZip($pdfBytes, $service->pdfFilename($dataset), (string)$password);
                $password = null;
                $service->recordProtectedGeneration($dataset, 'pdf_zip_aes256');
                $type = 'application/zip';
                $filename = $service->zipFilename($dataset, 'pdf');
            } else {
                $body = $pdfBytes;
                $service->recordPdfGeneration($dataset);
                $type = 'application/pdf';
                $filename = $service->pdfFilename($dataset);
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: ' . $type);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Content-Length: ' . strlen($body));
            echo $body;
            exit;

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
