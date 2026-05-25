<?php

namespace App\Services;

use App\Events\UserNotificationCreated;
use App\Mail\CaseActivityMail;
use App\Models\LawCase;
use App\Models\User;
use App\Notifications\InAppUserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CaseNotificationService
{
    public function __construct(
        private readonly AzureWebPubSubService $azureWebPubSubService,
    ) {}

    public function notifyCaseUpdate(LawCase $case, ?User $actor, string $actionLabel, string $summary): void
    {
        $this->notifyStakeholders($case, $actor, $actionLabel, $summary);
    }

    public function notifyDocumentUpload(LawCase $case, ?User $actor, string $fileName, string $category): void
    {
        $this->notifyStakeholders(
            $case,
            $actor,
            'Document Uploaded',
            sprintf('%s was uploaded to the %s section.', $fileName, $category)
        );
    }

    public function notifyDocumentDeleted(LawCase $case, ?User $actor, string $fileName, string $category): void
    {
        $this->notifyStakeholders(
            $case,
            $actor,
            'Document Deleted',
            sprintf('%s was deleted from the %s section.', $fileName, $category)
        );
    }

    private function notifyStakeholders(LawCase $case, ?User $actor, string $actionLabel, string $summary): void
    {
        $case->loadMissing(['lawyer:id,name,email,role', 'client:id,name,email,role']);

        $recipients = collect([$case->lawyer, $case->client])
            ->filter(fn ($user) => $user instanceof User && filled($user->email))
            ->filter(function (User $recipient) use ($actor) {
                if (!$actor) {
                    return true;
                }

                return (int) $recipient->id !== (int) $actor->id || strtolower((string) $actor->role) === 'admin';
            });

        $recipientIds = $recipients
            ->map(fn (User $recipient) => (int) $recipient->id)
            ->values();

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new InAppUserNotification([
                    'title' => $actionLabel,
                    'message' => sprintf('%s (Case #%d: %s)', $summary, (int) $case->caseId, (string) $case->title),
                    'category' => 'case',
                    'case_id' => (int) $case->caseId,
                    'actor_name' => $actor?->name,
                ]));

                // Broadcast real-time event so the frontend updates immediately
                broadcast(new UserNotificationCreated(
                    userId: (int) $recipient->id,
                    title: $actionLabel,
                    message: sprintf('%s (Case #%d: %s)', $summary, (int) $case->caseId, (string) $case->title),
                    category: 'case',
                ))->toOthers();

                if ($this->azureWebPubSubService->isEnabled()) {
                    $this->azureWebPubSubService->publishToUser(
                        userId: (int) $recipient->id,
                        payload: [
                            'event' => 'UserNotificationCreated',
                            'title' => $actionLabel,
                            'message' => sprintf('%s (Case #%d: %s)', $summary, (int) $case->caseId, (string) $case->title),
                            'category' => 'case',
                            'created_at' => now()->toIso8601String(),
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Case in-app notification failed', [
                    'case_id' => (int) $case->caseId,
                    'recipient_id' => (int) $recipient->id,
                    'action' => $actionLabel,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                Mail::to($recipient->email)->send(new CaseActivityMail(
                    actionLabel: $actionLabel,
                    caseTitle: (string) $case->title,
                    caseId: (int) $case->caseId,
                    summary: $summary,
                    actorName: $actor?->name,
                ));
            } catch (\Throwable $e) {
                Log::warning('Case notification email failed', [
                    'case_id' => (int) $case->caseId,
                    'recipient' => $recipient->email,
                    'action' => $actionLabel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Ensure the actor's open page can self-refresh via Web PubSub after create/update actions.
        if (
            $actor instanceof User &&
            $this->azureWebPubSubService->isEnabled() &&
            !$recipientIds->contains((int) $actor->id)
        ) {
            try {
                $this->azureWebPubSubService->publishToUser(
                    userId: (int) $actor->id,
                    payload: [
                        'event' => 'UserNotificationCreated',
                        'title' => $actionLabel,
                        'message' => sprintf('%s (Case #%d: %s)', $summary, (int) $case->caseId, (string) $case->title),
                        'category' => 'case',
                        'created_at' => now()->toIso8601String(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Case self-refresh realtime publish failed', [
                    'case_id' => (int) $case->caseId,
                    'actor_id' => (int) $actor->id,
                    'action' => $actionLabel,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}