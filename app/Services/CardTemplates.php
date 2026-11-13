<?php

namespace App\Services;

class CardTemplates
{
    public static function all(): array
    {
        return [
            'characters' => [
                'label' => 'Personajes',
                'templates' => ['character'],
                'fields' => ['identity', 'names', 'appearance', 'personality', 'motivations', 'fears', 'biography', 'capabilities', 'relationships', 'notes'],
            ],
            'places' => [
                'label' => 'Lugares',
                'templates' => ['place'],
                'fields' => ['type', 'containment', 'description', 'environment', 'inhabitants', 'government_groups', 'history'],
            ],
            'rules' => [
                'label' => 'Reglas',
                'templates' => ['rule'],
                'fields' => ['operation', 'scope', 'conditions', 'limits', 'costs', 'consequences', 'exceptions', 'affected'],
            ],
            'groups' => [
                'label' => 'Sociedades y grupos',
                'templates' => ['family', 'people', 'species', 'culture', 'religion', 'organization', 'government'],
                'fields' => ['purpose', 'characteristics', 'organization', 'members', 'seats', 'territory', 'relations'],
            ],
            'history' => [
                'label' => 'Historia',
                'templates' => ['event', 'epoch'],
                'fields' => ['participants', 'places', 'description', 'moment'],
            ],
            'objects' => [
                'label' => 'Objetos y recursos',
                'templates' => ['unique_object', 'resource'],
                'fields' => ['properties', 'uses', 'origins', 'custody', 'rules'],
            ],
            'languages' => [
                'label' => 'Lenguas y glosario',
                'templates' => ['language', 'glossary_term'],
                'fields' => ['speakers', 'writing', 'pronunciation', 'expressions', 'meaning', 'preferred_form', 'variants', 'examples'],
            ],
            'notes' => [
                'label' => 'Notas y documentación',
                'templates' => ['research', 'documentation', 'bibliography', 'pending'],
                'fields' => ['content', 'links', 'sources'],
            ],
        ];
    }
}
