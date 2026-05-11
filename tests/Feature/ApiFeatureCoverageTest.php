<?php

namespace Tests\Feature;

use App\Models\LawCase;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class ApiFeatureCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_routes_are_reachable(): void
    {
        $this->getJson('/api/test')
            ->assertOk();

        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJson([
                'message' => 'Connected to Laravel backend!',
            ]);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
        $this->getJson('/api/cases')->assertUnauthorized();
        $this->getJson('/api/meetings')->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_users_cases_and_meetings(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);

        // Extra users so list endpoints have meaningful data.
        User::factory()->create([
            'role' => 'lawyer',
            'status' => 'Active',
        ]);

        User::factory()->create([
            'role' => 'client',
            'status' => 'Active',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/users')
            ->assertOk();

        $this->getJson('/api/lawyers')
            ->assertOk();

        $this->getJson('/api/clients')
            ->assertOk();

        $this->getJson('/api/cases')
            ->assertOk();

        $this->getJson('/api/meetings')
            ->assertOk();
    }

    public function test_login_endpoint_returns_validation_error_for_missing_credentials(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_account_is_locked_after_three_failed_login_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'locked-user@example.com',
            'username' => 'locked-user',
            'password' => bcrypt('CorrectPassword123!'),
            'status' => 'Active',
        ]);

        $payload = [
            'email' => $user->email,
            'password' => 'wrong-password',
        ];

        $this->postJson('/api/login', $payload)->assertStatus(401);
        $this->postJson('/api/login', $payload)->assertStatus(401);

        $this->postJson('/api/login', $payload)
            ->assertStatus(423)
            ->assertJson([
                'code' => 'ACCOUNT_LOCKED',
                'reset_required' => true,
            ]);

        $user->refresh();
        $this->assertSame(3, (int) $user->failed_login_attempts);
        $this->assertNotNull($user->account_locked_at);
    }

    public function test_login_rejects_injection_style_payloads(): void
    {
        $user = User::factory()->create([
            'email' => 'secure-user@example.com',
            'username' => 'secure-user',
            'password' => bcrypt('StrongPassword123!'),
            'status' => 'Active',
        ]);

        // Reject non-string/object-like payloads for credential fields.
        $this->postJson('/api/login', [
            'email' => ['$ne' => ''],
            'password' => ['$gt' => ''],
        ])->assertStatus(422);

        // SQL-like password payload should not bypass auth.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => "' OR 1=1 --",
        ])->assertStatus(401);
    }

    public function test_password_reset_flow_endpoints_accept_request_shape(): void
    {
        $email = 'missing-user@example.com';

        $this->postJson('/api/password/send-otp', [
            'email' => $email,
        ])->assertStatus(422);

        $this->postJson('/api/password/verify-code', [
            'email' => $email,
            'otp' => '000000',
        ])->assertStatus(422);

        $this->postJson('/api/password/reset', [
            'email' => $email,
            'otp' => '000000',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertStatus(422);
    }

    public function test_can_get_user_public_key_by_firm_id_when_authenticated(): void
    {
        $authUser = User::factory()->create([
            'role' => 'admin',
            'status' => 'Active',
        ]);

        $targetUser = User::factory()->create([
            'role' => 'lawyer',
            'status' => 'Active',
            'rsa_public_key' => 'test-public-key',
        ]);

        Sanctum::actingAs($authUser);

        $this->getJson('/api/user/' . $targetUser->firmID . '/public-key')
            ->assertOk();
    }

    public function test_chatbot_ask_route_validates_question_payload(): void
    {
        $this->postJson('/api/ask', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question']);
    }

    public function test_chatbot_prompt_includes_playbook_booking_contact(): void
    {
        $requests = [];

        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->body();

            $payload = $request->data();
            if (($payload['model'] ?? null) === 'phi3:mini') {
                return Http::response([
                    'message' => [
                        'content' => '{"category":"civil"}',
                    ],
                ]);
            }

            return Http::response([
                'message' => [
                    'content' => 'Civil answer',
                ],
            ]);
        });

        $response = $this->postJson('/api/ask', [
            'question' => 'I need help with a breach of contract',
        ]);

        $response->assertOk();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('Booking Contact Name: [Iman Norhizam]', $requests[1]);
        $response->assertJsonPath('answer', 'Civil answer');
    }

    public function test_chatbot_returns_contact_immediately_for_explicit_handoff_request(): void
    {
        Http::fake();

        $response = $this->postJson('/api/ask', [
            'question' => 'I need a civil lawyer for a breach of contract case. Can you give me the contact?',
        ]);

        $response->assertOk();
        $response->assertJsonPath('category', 'civil');
        $response->assertJsonPath('model', 'aslaw-civil');
        $this->assertStringContainsString('Iman Norhizam (Partner Contact)', $response->json('answer'));
        $this->assertStringContainsString('Phone: 019-2630432', $response->json('answer'));
        Http::assertSentCount(0);
    }

    public function test_chatbot_uses_category_hint_for_fee_follow_up_without_domain_keywords(): void
    {
        Http::fake();

        $response = $this->postJson('/api/ask', [
            'question' => 'If I contact him, how much is the fees gonna be?',
            'category' => 'corporate',
        ]);

        $response->assertOk();
        $response->assertJsonPath('category', 'corporate');
        $response->assertJsonPath('model', 'aslaw-corporate');
        $this->assertStringContainsString('overview of Corporate Law estimated fees', $response->json('answer'));
        $this->assertStringContainsString('Iman Norhizam (Partner Contact)', $response->json('answer'));
        Http::assertSentCount(0);
    }

    public function test_chatbot_detects_and_corrects_typos(): void
    {
        Http::fake();

        $response = $this->postJson('/api/ask', [
            'question' => 'Why I need to report poliooce?',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Detected typo(s): poliooce → police', $response->json('answer'));
        $this->assertStringContainsString('Interpreting as: "Why I need to report police?"', $response->json('answer'));
    }

    public function test_admin_can_fetch_meetings_list(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'Active',
        ]);

        $lawyer = User::factory()->create([
            'role' => 'lawyer',
            'status' => 'Active',
            'email' => 'lawyer-meeting@example.com',
        ]);

        $client = User::factory()->create([
            'role' => 'client',
            'status' => 'Active',
            'email' => 'client-meeting@example.com',
        ]);

        $lawCase = LawCase::create([
            'title' => 'Admin Meeting Access Case',
            'caseType' => 'Litigation',
            'description' => 'Case for admin meeting fetch test',
            'lawyerID' => $lawyer->id,
            'clientID' => $client->id,
            'lawyerFirmID' => $lawyer->firmID,
            'clientFirmID' => $client->firmID,
            'status' => 'Active',
        ]);

        Meeting::create([
            'case_id' => (int) $lawCase->caseId,
            'organizer_user_id' => (int) $admin->id,
            'lawyerID' => (int) $lawyer->id,
            'clientID' => (int) $client->id,
            'meeting_method' => 'Online',
            'agenda' => 'Admin review agenda',
            'timezone' => 'Asia/Kuala_Lumpur',
            'start_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/meetings')
            ->assertOk()
            ->assertJsonStructure([
                'meetings' => [
                    [
                        'id',
                        'case_id',
                        'meeting_method',
                        'agenda',
                        'participants' => ['lawyer', 'client'],
                        'organizer',
                    ],
                ],
            ]);
    }

    public function test_admin_can_create_meeting_for_case(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'Active',
        ]);

        $lawyer = User::factory()->create([
            'role' => 'lawyer',
            'status' => 'Active',
            'email' => 'lawyer-create@example.com',
        ]);

        $client = User::factory()->create([
            'role' => 'client',
            'status' => 'Active',
            'email' => 'client-create@example.com',
        ]);

        $lawCase = LawCase::create([
            'title' => 'Admin Meeting Create Case',
            'caseType' => 'Litigation',
            'description' => 'Case for admin meeting create test',
            'lawyerID' => $lawyer->id,
            'clientID' => $client->id,
            'lawyerFirmID' => $lawyer->firmID,
            'clientFirmID' => $client->firmID,
            'status' => 'Active',
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createMeetingEvent')
                ->once()
                ->andReturn([
                    'event_id' => 'event_123',
                    'event_link' => 'https://calendar.google.com/event?eid=event_123',
                ]);
        });

        Sanctum::actingAs($admin);

        $startAt = now()->addDays(2)->setTime(9, 0, 0);
        $endAt = (clone $startAt)->addHour();

        $this->postJson('/api/meetings', [
            'case_id' => (int) $lawCase->caseId,
            'meeting_method' => 'Online',
            'agenda' => 'Admin schedules lawyer-client case meeting',
            'timezone' => 'Asia/Kuala_Lumpur',
            'start_at' => $startAt->toIso8601String(),
            'end_at' => $endAt->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('meeting.case_id', (int) $lawCase->caseId)
            ->assertJsonPath('meeting.participants.lawyer.id', (int) $lawyer->id)
            ->assertJsonPath('meeting.participants.client.id', (int) $client->id);

        $this->assertDatabaseHas('meetings', [
            'case_id' => (int) $lawCase->caseId,
            'organizer_user_id' => (int) $admin->id,
            'lawyerID' => (int) $lawyer->id,
            'clientID' => (int) $client->id,
            'meeting_method' => 'Online',
        ]);
    }

    public function test_lawyer_can_schedule_only_for_own_case(): void
    {
        $lawyer = User::factory()->create([
            'role' => 'lawyer',
            'status' => 'Active',
            'email' => 'lawyer-owncase@example.com',
        ]);

        $ownClient = User::factory()->create([
            'role' => 'client',
            'status' => 'Active',
            'email' => 'own-client@example.com',
        ]);

        $otherLawyer = User::factory()->create([
            'role' => 'lawyer',
            'status' => 'Active',
            'email' => 'other-lawyer@example.com',
        ]);

        $otherClient = User::factory()->create([
            'role' => 'client',
            'status' => 'Active',
            'email' => 'other-client@example.com',
        ]);

        $ownCase = LawCase::create([
            'title' => 'Lawyer Own Case',
            'caseType' => 'Litigation',
            'description' => 'Lawyer-owned case',
            'lawyerID' => $lawyer->id,
            'clientID' => $ownClient->id,
            'lawyerFirmID' => $lawyer->firmID,
            'clientFirmID' => $ownClient->firmID,
            'status' => 'Active',
        ]);

        $otherCase = LawCase::create([
            'title' => 'Unrelated Case',
            'caseType' => 'Litigation',
            'description' => 'Case not linked to acting lawyer',
            'lawyerID' => $otherLawyer->id,
            'clientID' => $otherClient->id,
            'lawyerFirmID' => $otherLawyer->firmID,
            'clientFirmID' => $otherClient->firmID,
            'status' => 'Active',
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createMeetingEvent')
                ->once()
                ->andReturn([
                    'event_id' => 'event_lawyer_own',
                    'event_link' => 'https://calendar.google.com/event?eid=event_lawyer_own',
                ]);
        });

        Sanctum::actingAs($lawyer);

        $ownStart = now()->addDays(3)->setTime(10, 0, 0);
        $ownEnd = (clone $ownStart)->addHour();

        $this->postJson('/api/meetings', [
            'case_id' => (int) $ownCase->caseId,
            'meeting_method' => 'Online',
            'agenda' => 'Lawyer schedules own case meeting',
            'timezone' => 'Asia/Kuala_Lumpur',
            'start_at' => $ownStart->toIso8601String(),
            'end_at' => $ownEnd->toIso8601String(),
        ])->assertCreated();

        $blockedStart = now()->addDays(4)->setTime(10, 0, 0);
        $blockedEnd = (clone $blockedStart)->addHour();

        $this->postJson('/api/meetings', [
            'case_id' => (int) $otherCase->caseId,
            'meeting_method' => 'Online',
            'agenda' => 'Lawyer attempts unrelated case meeting',
            'timezone' => 'Asia/Kuala_Lumpur',
            'start_at' => $blockedStart->toIso8601String(),
            'end_at' => $blockedEnd->toIso8601String(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You can only schedule meetings for cases connected to your account.');
    }

    public function test_client_can_schedule_only_for_own_case(): void
    {
        $ownLawyer = User::factory()->create([
            'role' => 'lawyer',
            'status' => 'Active',
            'email' => 'own-lawyer@example.com',
        ]);

        $client = User::factory()->create([
            'role' => 'client',
            'status' => 'Active',
            'email' => 'client-owncase@example.com',
        ]);

        $otherLawyer = User::factory()->create([
            'role' => 'lawyer',
            'status' => 'Active',
            'email' => 'other-lawyer-client-test@example.com',
        ]);

        $otherClient = User::factory()->create([
            'role' => 'client',
            'status' => 'Active',
            'email' => 'other-client-client-test@example.com',
        ]);

        $ownCase = LawCase::create([
            'title' => 'Client Own Case',
            'caseType' => 'Litigation',
            'description' => 'Client-owned case',
            'lawyerID' => $ownLawyer->id,
            'clientID' => $client->id,
            'lawyerFirmID' => $ownLawyer->firmID,
            'clientFirmID' => $client->firmID,
            'status' => 'Active',
        ]);

        $otherCase = LawCase::create([
            'title' => 'Unrelated Client Case',
            'caseType' => 'Litigation',
            'description' => 'Case not linked to acting client',
            'lawyerID' => $otherLawyer->id,
            'clientID' => $otherClient->id,
            'lawyerFirmID' => $otherLawyer->firmID,
            'clientFirmID' => $otherClient->firmID,
            'status' => 'Active',
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createMeetingEvent')
                ->once()
                ->andReturn([
                    'event_id' => 'event_client_own',
                    'event_link' => 'https://calendar.google.com/event?eid=event_client_own',
                ]);
        });

        Sanctum::actingAs($client);

        $ownStart = now()->addDays(5)->setTime(11, 0, 0);
        $ownEnd = (clone $ownStart)->addHour();

        $this->postJson('/api/meetings', [
            'case_id' => (int) $ownCase->caseId,
            'meeting_method' => 'Online',
            'agenda' => 'Client schedules own case meeting',
            'timezone' => 'Asia/Kuala_Lumpur',
            'start_at' => $ownStart->toIso8601String(),
            'end_at' => $ownEnd->toIso8601String(),
        ])->assertCreated();

        $blockedStart = now()->addDays(6)->setTime(11, 0, 0);
        $blockedEnd = (clone $blockedStart)->addHour();

        $this->postJson('/api/meetings', [
            'case_id' => (int) $otherCase->caseId,
            'meeting_method' => 'Online',
            'agenda' => 'Client attempts unrelated case meeting',
            'timezone' => 'Asia/Kuala_Lumpur',
            'start_at' => $blockedStart->toIso8601String(),
            'end_at' => $blockedEnd->toIso8601String(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You can only schedule meetings for cases connected to your account.');
    }
}
