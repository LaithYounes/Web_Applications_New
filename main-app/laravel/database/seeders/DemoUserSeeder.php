<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoUserSeeder extends Seeder
{
    private const PASSWORD = 'StoreDemo2026!';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo accounts can only be seeded in local or testing environments.');
        }

        $accounts = [
            ['Emily Carter', 'emily.carter@example.test', 'admin'],
            ['Daniel Brooks', 'daniel.brooks@example.test', 'customer'],
            ['Olivia Bennett', 'olivia.bennett@example.test', 'customer'],
            ['Sophie Miller', 'sophie.miller@example.test', 'customer'],
            ['James Turner', 'james.turner@example.test', 'customer'],
            ['Grace Wilson', 'grace.wilson@example.test', 'customer'],
            ['Ethan Parker', 'ethan.parker@example.test', 'customer'],
            ['Mia Collins', 'mia.collins@example.test', 'customer'],
            ['Noah Reed', 'noah.reed@example.test', 'customer'],
            ['Ava Morgan', 'ava.morgan@example.test', 'customer'],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => self::PASSWORD, 'role' => $role],
            );

            if ($user->role !== $role) {
                throw new RuntimeException("The existing demo account {$email} has a different role.");
            }
        }
    }
}
