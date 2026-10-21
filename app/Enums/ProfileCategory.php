<?php

namespace App\Enums;

enum ProfileCategory: string
{
    case TourismCompany = 'tourism_company';
    case Guide = 'guide';
    case Driver = 'driver';
    case Instructor = 'instructor';
    case TransportCompany = 'transport_company';

    public function label(): string
    {
        return match ($this) {
            self::TourismCompany => __('Empresa turística'),
            self::Guide => __('Guía'),
            self::Driver => __('Chofer'),
            self::Instructor => __('Instructor'),
            self::TransportCompany => __('Empresa de transporte'),
        };
    }

    public function isReceiver(): bool
    {
        return $this !== self::TourismCompany;
    }

    public function hasAvailability(): bool
    {
        return $this !== self::TourismCompany;
    }
}
