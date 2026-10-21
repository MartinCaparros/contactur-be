<?php

namespace Database\Seeders;

use App\Enums\ProfileCategory;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\Language;
use App\Models\Service;
use App\Models\Specialty;
use Illuminate\Database\Seeder;

final class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Ushuaia', 'province_code' => 'TF', 'country_code' => 'AR', 'timezone' => 'America/Argentina/Ushuaia'],
            ['name' => 'El Calafate', 'province_code' => 'SC', 'country_code' => 'AR', 'timezone' => 'America/Argentina/Rio_Gallegos'],
            ['name' => 'Bariloche', 'province_code' => 'RN', 'country_code' => 'AR', 'timezone' => 'America/Argentina/Salta'],
            ['name' => 'Río Grande', 'province_code' => 'TF', 'country_code' => 'AR', 'timezone' => 'America/Argentina/Ushuaia'],
            ['name' => 'Mendoza', 'province_code' => 'MZ', 'country_code' => 'AR', 'timezone' => 'America/Argentina/Mendoza'],
        ] as $city) {
            City::query()->updateOrCreate(
                ['name' => $city['name'], 'province_code' => $city['province_code'], 'country_code' => $city['country_code']],
                ['timezone' => $city['timezone']],
            );
        }

        $this->seedCatalog(Language::class, [
            ['code' => 'es', 'name' => 'Español'],
            ['code' => 'en', 'name' => 'Inglés'],
            ['code' => 'pt', 'name' => 'Portugués'],
        ]);
        $this->seedCatalog(Service::class, [
            ['code' => 'tour_operations', 'name' => 'Operación de excursiones'],
            ['code' => 'guiding', 'name' => 'Guiado'],
            ['code' => 'passenger_transfer', 'name' => 'Traslado de pasajeros'],
            ['code' => 'outdoor_instruction', 'name' => 'Instrucción de actividades'],
            ['code' => 'group_transport', 'name' => 'Transporte de grupos'],
        ]);
        $this->seedCatalog(Specialty::class, [
            ['code' => 'trekking', 'name' => 'Trekking'],
            ['code' => 'skiing', 'name' => 'Esquí'],
            ['code' => 'mountaineering', 'name' => 'Montañismo'],
        ]);

        foreach ([
            [ProfileCategory::TourismCompany, 'tourism_company_registration', 'Habilitación de empresa turística', false],
            [ProfileCategory::Guide, 'guide_license', 'Habilitación profesional de guía', true],
            [ProfileCategory::Driver, 'driver_license', 'Licencia profesional de conducir', true],
            [ProfileCategory::Instructor, 'instructor_certification', 'Certificación profesional de instructor', true],
            [ProfileCategory::TransportCompany, 'transport_company_registration', 'Habilitación de empresa de transporte', true],
        ] as [$category, $code, $label, $requiresExpiration]) {
            DocumentType::query()->updateOrCreate(
                ['category' => $category->value, 'code' => $code],
                [
                    'label' => $label,
                    'requires_expiration' => $requiresExpiration,
                    'required' => true,
                    'jurisdiction' => 'AR',
                ],
            );
        }
    }

    /** @param class-string<Language|Service|Specialty> $model @param array<int, array{code: string, name: string}> $items */
    private function seedCatalog(string $model, array $items): void
    {
        foreach ($items as $item) {
            $model::query()->updateOrCreate(['code' => $item['code']], ['name' => $item['name']]);
        }
    }
}
