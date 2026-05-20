<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LawCase;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\InAppUserNotification;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeetingController extends Controller
{
    public function __construct(private readonly GoogleCalendarService $calendarService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $role = strtolower((string) $user->role);

        if (!in_array($role, ['admin', 'adminstaff', 'junioradmin', 'client', 'lawyer'], true)) {
            return response()->json(['message' => 'Only admin, junior admin, client, or lawyer can access meetings.'], 403);
        }

        $meetings = Meeting::with([
                'lawCase:caseId,title,lawyerID,clientID',
                'lawyer:id,name,email,firmID',
                'client:id,name,email,firmID',
                'organizer:id,name,email,firmID',
            ])
            ->when($role === 'lawyer', fn ($query) => $query->where('lawyerID', $user->id))
            ->when($role === 'client', fn ($query) => $query->where('clientID', $user->id))
            ->orderBy('start_at')
            ->get();

        return response()->json([
            'meetings' => $meetings->map(fn (Meeting $meeting) => $this->toPayload($meeting)),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $role = strtolower((string) $user->role);

        if (!in_array($role, ['admin', 'adminstaff', 'junioradmin', 'client', 'lawyer'], true)) {
            return response()->json(['message' => 'Only admin, junior admin, client, or lawyer can schedule meetings.'], 403);
        }

        $validated = $request->validate([
            'case_id' => 'required|integer|exists:law_cases,caseId',
            'meeting_method' => 'required|in:Online,In Person',
            'agenda' => 'required|string|max:2000',
            'timezone' => 'required|timezone',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
        ]);

        $lawCase = LawCase::with([
            'lawyer:id,name,email,firmID',
            'client:id,name,email,firmID',
        ])->findOrFail((int) $validated['case_id']);

        if (
            $role !== 'admin'
            && $role !== 'adminstaff'
            && $role !== 'junioradmin'
            && (int) $lawCase->lawyerID !== (int) $user->id
            && (int) $lawCase->clientID !== (int) $user->id
        ) {
            return response()->json([
                'message' => 'You can only schedule meetings for cases connected to your account.',
            ], 403);
        }

        if (!$lawCase->lawyer?->email || !$lawCase->client?->email) {
            return response()->json([
                'message' => 'Both lawyer and client must have valid email before scheduling.',
            ], 422);
        }

        $timezone = (string) $validated['timezone'];
        $start = Carbon::parse((string) $validated['start_at'], $timezone);
        $end = Carbon::parse((string) $validated['end_at'], $timezone);

        $attendees = [
            ['email' => (string) $lawCase->lawyer->email, 'displayName' => (string) $lawCase->lawyer->name],
            ['email' => (string) $lawCase->client->email, 'displayName' => (string) $lawCase->client->name],
        ];

        $eventTitle = sprintf(
            'Case #%d Meeting: %s',
            (int) $lawCase->caseId,
            (string) $lawCase->title
        );

        $eventDescription = sprintf(
            "Scheduled via ASLAW\nCase ID: %d\nCase Title: %s\nLawyer: %s\nClient: %s\nMethod: %s\nAgenda: %s",
            (int) $lawCase->caseId,
            (string) $lawCase->title,
            (string) $lawCase->lawyer->name,
            (string) $lawCase->client->name,
            (string) $validated['meeting_method'],
            trim((string) $validated['agenda'])
        );

        $calendarResult = $this->calendarService->createMeetingEvent([
            'title' => $eventTitle,
            'description' => $eventDescription,
            'timezone' => $timezone,
            'start_date_time' => $start->toRfc3339String(),
            'end_date_time' => $end->toRfc3339String(),
            'attendees' => $attendees,
        ]);

        $meeting = DB::transaction(function () use ($validated, $lawCase, $user, $timezone, $start, $end, $calendarResult) {
            return Meeting::create([
                'case_id' => (int) $lawCase->caseId,
                'organizer_user_id' => (int) $user->id,
                'lawyerID' => (int) $lawCase->lawyerID,
                'clientID' => (int) $lawCase->clientID,
                'meeting_method' => (string) $validated['meeting_method'],
                'agenda' => trim((string) $validated['agenda']),
                'timezone' => $timezone,
                'start_at' => $start->clone()->utc()->toDateTimeString(),
                'end_at' => $end->clone()->utc()->toDateTimeString(),
                'google_event_id' => $calendarResult['event_id'] ?? null,
            ]);
        });

        $meeting->load([
            'lawCase:caseId,title,lawyerID,clientID',
            'lawyer:id,name,email,firmID',
            'client:id,name,email,firmID',
            'organizer:id,name,email,firmID',
        ]);

        $this->notifyMeetingParticipants($meeting, $user);

        return response()->json([
            'message' => 'Meeting scheduled, Google Calendar event created, and invites sent.',
            'meeting' => [
                ...$this->toPayload($meeting),
                'google_event_link' => $calendarResult['event_link'] ?? null,
            ],
        ], 201);
    }

    private function toPayload(Meeting $meeting): array
    {
        $tz = $meeting->timezone ?: 'Asia/Kuala_Lumpur';

        return [
            'id' => $meeting->id,
            'case_id' => $meeting->case_id,
            'case_title' => $meeting->lawCase?->title,
            'meeting_method' => $meeting->meeting_method,
            'agenda' => $meeting->agenda,
            'timezone' => $tz,
            'start_at' => optional($meeting->start_at)?->copy()->timezone($tz)?->toIso8601String(),
            'end_at' => optional($meeting->end_at)?->copy()->timezone($tz)?->toIso8601String(),
            'google_event_id' => $meeting->google_event_id,
            'participants' => [
                'lawyer' => [
                    'id' => $meeting->lawyer?->id,
                    'firmID' => $meeting->lawyer?->firmID,
                    'name' => $meeting->lawyer?->name,
                    'email' => $meeting->lawyer?->email,
                ],
                'client' => [
                    'id' => $meeting->client?->id,
                    'firmID' => $meeting->client?->firmID,
                    'name' => $meeting->client?->name,
                    'email' => $meeting->client?->email,
                ],
            ],
            'organizer' => [
                'id' => $meeting->organizer?->id,
                'firmID' => $meeting->organizer?->firmID,
                'name' => $meeting->organizer?->name,
                'email' => $meeting->organizer?->email,
            ],
            'created_at' => $meeting->created_at,
            'updated_at' => $meeting->updated_at,
        ];
    }

    private function notifyMeetingParticipants(Meeting $meeting, User $actor): void
    {
        $recipients = collect([
            $meeting->lawyer,
            $meeting->client,
            $meeting->organizer,
        ])
            ->filter(fn ($user) => $user instanceof User)
            ->unique(fn (User $user) => (int) $user->id);

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new InAppUserNotification([
                    'title' => 'Meeting Scheduled',
                    'message' => sprintf(
                        'Case #%d: %s - %s',
                        (int) $meeting->case_id,
                        (string) ($meeting->lawCase?->title ?? 'Untitled Case'),
                        (string) $meeting->agenda
                    ),
                    'category' => 'meeting',
                    'case_id' => (int) $meeting->case_id,
                    'meeting_id' => (int) $meeting->id,
                    'actor_name' => $actor->name,
                    'meta' => [
                        'meeting_method' => $meeting->meeting_method,
                        'start_at' => optional($meeting->start_at)?->toIso8601String(),
                        'timezone' => $meeting->timezone,
                    ],
                ]));
            } catch (\Throwable $e) {
                Log::warning('Meeting in-app notification failed', [
                    'meeting_id' => (int) $meeting->id,
                    'recipient_id' => (int) $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
