<?php

namespace App\Modules\Users\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AuthService
{
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $user->refresh();

            $token = auth('api')->login($user);

            return [
                'user' => $user,
                'token' => $token,
            ];
        });
    }

    public function login(array $credentials): ?array
    {
        $token = auth('api')->attempt($credentials);

        if (!$token) {
            return null;
        }

        return [
            'user' => auth('api')->user(),
            'token' => $token,
        ];
    }

    public function logout(): void
    {
        auth('api')->logout();
    }

    public function getAuthenticatedUser(): User
    {
        return auth('api')->user();
    }
}
