# FPDF 1.86 (vendored, unmodified)

- Source: http://www.fpdf.org (download of 2023-06-25, file `fpdf.php`; with the four Helvetica core-font metric files in `font/`; no other fonts).
- Author: Olivier Plathey.
- Licence: FPDF's own permissive licence, reproduced in `LICENSE.txt`: permission to use, copy, modify, distribute, sublicense and/or sell, with no usage restriction and no warranty.
- Used only by `modules/curriculum/services/CurriculumPdfRenderer.php` (Curriculum Export PDF). PDF-level encryption is NOT used; protected exports put the PDF inside the AES-256 ZIP.
- This folder is not meant to be web-served: `.htaccess` denies direct requests.
