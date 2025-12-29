<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class UserFirmIdTest extends TestCase
{
    use RefreshDatabase;

    public function admin_firm_id_starts_with_d001()
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->assertEquals('D001', $admin->firmID);
    }

    public function lawyer_firm_id_starts_with_y001()
    {
        $lawyer = User::factory()->create([
            'role' => 'lawyer',
        ]);

        $this->assertEquals('Y001', $lawyer->firmID);
    }

    public function client_firm_id_starts_with_e001()
    {
        $client = User::factory()->create([
            'role' => 'client',
        ]);

        $this->assertEquals('E001', $client->firmID);
    }

    public function firm_id_increments_correctly_per_role()
    {
        User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin']);

        $thirdAdmin = User::factory()->create(['role' => 'admin']);

        $this->assertEquals('D003', $thirdAdmin->firmID);
    }

    public function firm_id_cannot_be_modified_after_creation()
    {
        $user = User::factory()->create([
            'role' => 'client',
        ]);

        $this->expectException(\Exception::class);

        $user->update([
            'firmID' => 'E999',
        ]);
    }

}
