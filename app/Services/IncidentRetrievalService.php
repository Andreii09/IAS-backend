<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Authoritative answer for incident/report questions.
 *
 * Verified migrations, models, routes and screens contain no incident,
 * emergency, disaster, report-status or field-record module. The service
 * returns that limitation honestly instead of inventing a workflow.
 */
final class IncidentRetrievalService
{
    public const REPLY = 'Bantay Cordova currently does not include an incident-reporting, report-status, emergency or disaster module. There are no submitted incident reports or report statuses to retrieve in the system. For help with an incident, contact the barangay hall.';

    /**
     * @return array{status: string, reply: string}
     */
    public function retrieve(): array
    {
        return ['status' => 'not_implemented', 'reply' => self::REPLY];
    }
}
