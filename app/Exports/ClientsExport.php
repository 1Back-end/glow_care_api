<?php

namespace App\Exports;

use App\Models\Client;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ClientsExport implements FromQuery, WithHeadings, WithMapping
{
    protected $establishmentUuid;

    public function __construct($establishmentUuid)
    {
        $this->establishmentUuid = $establishmentUuid;
    }

    public function query()
    {
        return Client::where('establishment_uuid', $this->establishmentUuid)
            ->with(['creator', 'updater'])
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'N° (Code)',
            'Nom complet',
            'Téléphone',
            'Second téléphone',
            'WhatsApp',
            'Email',
            'Date de naissance',
            'Adresse',
            'Notes',
            'Total des prestations',
            'Récompense(s)',
            'Niveau de fidélité',
            'Statut',
            'Créé par',
            'Dernière mise à jour par',
            'Date de création',
        ];
    }

    public function map($client): array
    {
        return [
            $client->code,
            $client->full_name,
            $client->phone,
            $client->second_phone,
            $client->is_watsapp ? 'Oui' : 'Non',
            $client->email,
            $client->birth_date?->format('d/m/Y'),
            $client->address,
            $client->notes,
            $client->count_prestations,
            $client->current_loyalty_reward?->label ?? '',
            $client->client_status_label,
            $client->is_active ? 'Actif' : 'Inactif',
            $client->creator?->nom_utilisateur ?? '',
            $client->updater?->nom_utilisateur ?? '',
            $client->created_at?->format('d/m/Y H:i'),
        ];
    }
}
