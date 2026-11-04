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

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
