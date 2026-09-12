<?php

namespace App\Support;

/** Navigation only: callers retain each page's existing read and action permissions. */
final class LegislatureWorkspace
{
    public static function for(object $legislature, ?object $place, bool $canReadSpeaker): array
    {
        $id = (string) $legislature->id;
        $base = '/legislatures/'.$id;

        return [
            'id' => $id,
            'hasSpeaker' => ($legislature->speaker_id ?? null) !== null,
            'place' => $place ? [
                'name' => $place->name,
                'href' => '/jurisdictions/'.rawurlencode($place->slug),
            ] : null,
            'overview' => '/legislatures/'.rawurlencode($place?->slug ?: $id),
            'chamber' => $base.'/chamber',
            'session' => $base.'/session',
            'speaker' => $canReadSpeaker ? $base.'/speaker' : null,
            'maps' => $base.'/districts',
            'bills' => $base.'/bills',
            'committees' => $base.'/committees',
            'oversight' => $base.'/oversight',
            'referendums' => $base.'/referendums',
            'settings' => $base.'/settings',
            'rooms' => $place ? '/civic/commons/halls?jurisdiction='.rawurlencode($place->id) : null,
        ];
    }
}
