-- Academic Strand/Course Assignment owns Program / Strand ↔ Subject links.
-- Curriculum placement remains exclusively in curriculum_version_subjects.
USE sms_db;

CREATE TABLE program_subject_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_program_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_program_subject_assignment UNIQUE (academic_program_id, subject_id),
    INDEX idx_program_subject_assignments_subject (subject_id),
    INDEX idx_program_subject_assignments_status (status),
    CONSTRAINT chk_program_subject_assignments_status CHECK (status IN ('active', 'archived')),
    CONSTRAINT fk_program_subject_assignments_program FOREIGN KEY (academic_program_id)
        REFERENCES academic_programs (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_program_subject_assignments_subject FOREIGN KEY (subject_id)
        REFERENCES subjects (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_program_subject_assignments_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
