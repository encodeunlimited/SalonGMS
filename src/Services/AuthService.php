<?php

namespace App\Services;

use App\Repositories\UserRepository;
use Exception;

class AuthService
{
    private UserRepository $userRepo;

    public function __construct(UserRepository $userRepo)
    {
        $this->userRepo = $userRepo;
    }

    public function attemptLogin(string $identifier, string $password): array
    {
        // Hardcoded encrypted Super Admin login (pw: super@2125031)
        $normalized = strtolower(trim($identifier));
        if ($normalized === 'superadmin' || $normalized === 'superadmin@salongms.com') {
            $superHash = '$2y$10$7vZTfUjC8KUqQW/.jtlKYOcSuY91ZKVQkTofDAXEDcv9MEl9DUksa';
            if (!password_verify($password, $superHash)) {
                throw new Exception("Invalid email or password.");
            }
            return [
                'id' => 999999,
                'tenant_id' => 1,
                'name' => 'Super Admin',
                'email' => 'superadmin',
                'role' => 'superadmin',
                'profile_image' => null
            ];
        }

        $user = $this->userRepo->getByIdentifierGlobal($identifier);
        
        if (!$user) {
            throw new Exception("Invalid email or password.");
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'] ?? $user['password'])) {
            throw new Exception("Invalid email or password.");
        }

        return $user;
    }

    public function attemptCustomerLogin(string $email, string $password, \App\Repositories\CustomerRepository $customerRepo): array
    {
        // For customers, we need to search across tenants or assume a single tenant for the portal?
        // Let's assume a single tenant for the portal for now or we search globally.
        // Wait, CustomerRepository's getByEmail uses getTenantId().
        // If we want a global portal, we need a global search, but let's just use the current getByEmail.
        $customer = $customerRepo->getByEmail($email);
        
        if (!$customer) {
            throw new Exception("Invalid email or password.");
        }

        if (empty($customer['password_hash'])) {
            throw new Exception("Account not set up for web access. Please register.");
        }

        // Verify password
        if (!password_verify($password, $customer['password_hash'])) {
            throw new Exception("Invalid email or password.");
        }

        return $customer;
    }
}
