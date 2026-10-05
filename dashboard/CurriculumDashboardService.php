<?php

require_once __DIR__ . '/CurriculumDashboardRepository.php';

class CurriculumDashboardService
{
    private CurriculumDashboardRepository $repository;

    public function __construct(mysqli $db)
    {
        $this->repository = new CurriculumDashboardRepository($db);
    }

    public function overview(bool $includeRecentActivity): array
    {
        $rawCounts = $this->repository->summaryCounts();
        $counts = [];
        foreach ($rawCounts as $name => $value) {
            $counts[$name] = (int)$value;
        }

        $activity = [];
        if ($includeRecentActivity) {
            foreach ($this->repository->recentCurriculumActivity(8) as $row) {
                $firstName = trim((string)($row['first_name'] ?? ''));
                $lastName = trim((string)($row['last_name'] ?? ''));
                $fullName = trim($firstName . ' ' . $lastName);
                $username = trim((string)($row['username'] ?? ''));
                $userLabel = $fullName !== '' ? $fullName : ($username !== '' ? $username : 'System');

                $activity[] = [
                    'action' => (string)$row['action'],
                    'action_label' => $this->actionLabel((string)$row['action']),
                    'entity_type' => (string)$row['entity_type'],
                    'entity_id' => $row['entity_id'] === null ? null : (int)$row['entity_id'],
                    'description' => (string)$row['description'],
                    'created_at' => (string)$row['created_at'],
                    'user_label' => $userLabel,
                ];
            }
        }

        return [
            'counts' => $counts,
            'version_statuses' => $this->repository->versionStatusCounts(),
            'recent_activity' => $activity,
        ];
    }

    public function programs(): array
    {
        return $this->repository->visiblePrograms();
    }

    public function subjectMasterCount(): int
    {
        return $this->repository->subjectMasterCount();
    }

    private function actionLabel(string $action): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $action)));
    }
}
