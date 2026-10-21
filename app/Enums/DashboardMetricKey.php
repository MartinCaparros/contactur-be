<?php

namespace App\Enums;

/**
 * Claves de `DashboardMetric.key` del contrato, en el orden en que se informan.
 * Ninguna mide contrataciones ni servicios realizados: un match aceptado solo
 * libera el contacto (AGENTS.md).
 */
enum DashboardMetricKey: string
{
    case PendingContactRequests = 'pending_contact_requests';
    case AcceptedContactRequests = 'accepted_contact_requests';
    case RejectedContactRequests = 'rejected_contact_requests';
    case ExpiredContactRequests = 'expired_contact_requests';
    case UnreadNotifications = 'unread_notifications';
    case VisibleReferences = 'visible_references';
    case AvailableFutureDays = 'available_future_days';
}
