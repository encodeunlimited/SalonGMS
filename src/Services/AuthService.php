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

    public function attemptLogin(string $email, string $password): array
    {
        $user = $this->userRepo->getByEmailGlobal($email);
        
        if (!$user) {
            throw new Exception("Invalid email or password.");
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            throw new Exception("Invalid email or password.");
        }

        return $user;
    }
}
