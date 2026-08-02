<?php

namespace App\Services;

use App\Repositories\AppointmentRepository;
use Exception;

class AppointmentService extends BaseService
{
    private AppointmentRepository $repository;

    public function __construct(AppointmentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Override setTenantId to also cascade to the repository.
     */
    public function setTenantId(int $tenantId): self
    {
        parent::setTenantId($tenantId);
        $this->repository->setTenantId($tenantId);
        return $this;
    }

    /**
     * Creates an appointment with conflict checking logic.
     */
    public function createAppointment(array $data): array
    {
        $date = $data['date'] ?? date('Y-m-d');
        $time = $data['time'] ?? '12:00';
        $stylist = $data['stylist'] ?? 'Unknown';

        // Fetch existing appointments for that specific stylist on that day
        $existingAppointments = $this->repository->getByDateAndStylist($date, $stylist);

        foreach ($existingAppointments as $apt) {
            // For Phase 4, we do a simple exact-match time check.
            // A full implementation would check overlap using service duration_minutes.
            if ($apt['apt_time'] === $time) {
                throw new Exception("Stylist '{$stylist}' is already booked at {$time} on {$date}. Please choose another time.");
            }
        }

        return $this->repository->create($data);
    }
}
