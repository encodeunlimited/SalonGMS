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

        $newStart = strtotime("$date $time");
        $newEnd = strtotime("$date " . ($data['end_time'] ?? date('H:i', strtotime("$time +1 hour"))));

        foreach ($existingAppointments as $apt) {
            $aptStart = strtotime($apt['apt_date'] . ' ' . $apt['apt_time']);
            $aptEnd = strtotime($apt['apt_date'] . ' ' . ($apt['apt_end_time'] ?? date('H:i', strtotime($apt['apt_time'] . ' +1 hour'))));

            if ($newStart < $aptEnd && $newEnd > $aptStart && strtolower($apt['status']) !== 'cancelled') {
                throw new Exception("Stylist '{$stylist}' is already booked during this time on {$date}. Please choose another time.");
            }
        }

        return $this->repository->create($data);
    }
}
