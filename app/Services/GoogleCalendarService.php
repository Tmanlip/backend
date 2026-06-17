<?php

namespace App\Services;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use InvalidArgumentException;

class GoogleCalendarService
{
    public function createMeetingEvent(array $payload): array
    {
        $calendarId = (string) config('services.google_calendar.calendar_id');
        $credentialsPath = (string) config('services.google_calendar.credentials_json');

        if ($calendarId === '' || $credentialsPath === '') {
            throw new InvalidArgumentException('Google Calendar is not configured. Set GOOGLE_CALENDAR_ID and GOOGLE_CALENDAR_CREDENTIALS_JSON.');
        }

        if (!file_exists($credentialsPath)) {
            throw new InvalidArgumentException('Google service-account credentials file not found.');
        }

        $client = new Client();
        $client->setApplicationName((string) config('app.name', 'ASALAW') . ' Meeting Scheduler');
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([Calendar::CALENDAR]);

        $delegatedUser = (string) config('services.google_calendar.delegated_user');
        if ($delegatedUser !== '') {
            $client->setSubject($delegatedUser);
        }

        $service = new Calendar($client);

        $event = new Event([
            'summary' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'location' => $payload['location'] ?? null,
            'start' => [
                'dateTime' => $payload['start_date_time'],
                'timeZone' => $payload['timezone'],
            ],
            'end' => [
                'dateTime' => $payload['end_date_time'],
                'timeZone' => $payload['timezone'],
            ],
            'attendees' => $payload['attendees'] ?? [],
        ]);

        $created = $service->events->insert($calendarId, $event, [
            'sendUpdates' => 'all',
        ]);

        return [
            'event_id' => $created->getId(),
            'event_link' => $created->getHtmlLink(),
        ];
    }
}
