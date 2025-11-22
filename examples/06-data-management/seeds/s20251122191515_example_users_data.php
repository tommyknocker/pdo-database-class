<?php
declare(strict_types=1);
use tommyknocker\pdodb\seeds\Seed;

class ExampleUsersDataSeed extends Seed
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'role' => 'admin',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'role' => 'user',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Bob Johnson',
                'email' => 'bob@example.com',
                'role' => 'moderator',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->insertMulti('users', $users);
    }

    public function rollback(): void
    {
        $this->delete('users', ['email' => 'john@example.com']);
        $this->delete('users', ['email' => 'jane@example.com']);
        $this->delete('users', ['email' => 'bob@example.com']);
    }
}