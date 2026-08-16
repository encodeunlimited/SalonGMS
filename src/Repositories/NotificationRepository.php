<?php

namespace App\Repositories;

use PDO;

class NotificationRepository
{
    private PDO $pdo;
    private int $tenantId;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function setTenantId(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getUnreadCount(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE tenant_id = :tenant_id AND is_read = 0");
        $stmt->execute(['tenant_id' => $this->tenantId]);
        return (int)$stmt->fetchColumn();
    }

    public function getRecent(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM notifications WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':tenant_id', $this->tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markAsRead(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND tenant_id = :tenant_id");
        return $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->tenantId
        ]);
    }

    public function markAllAsRead(): bool
    {
        $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE tenant_id = :tenant_id");
        return $stmt->execute(['tenant_id' => $this->tenantId]);
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO notifications (tenant_id, type, title, message) VALUES (:tenant_id, :type, :title, :message)");
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'type' => $data['type'] ?? 'system',
            'title' => $data['title'],
            'message' => $data['message']
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
