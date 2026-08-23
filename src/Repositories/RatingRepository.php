<?php
namespace App\Repositories;

use PDO;

class RatingRepository extends BaseRepository
{
    protected string $table = 'ratings';

    public function createRating(int $customerId, int $rating, string $comment = ''): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, customer_id, rating, comment, created_at)
            VALUES (:tenant_id, :customer_id, :rating, :comment, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'customer_id' => $customerId,
            'rating' => $rating,
            'comment' => $comment
        ]);

        return (int)$this->db->lastInsertId();
    }
}
