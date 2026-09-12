<?php

namespace App\Support;

/** Navigation only: callers retain each page's existing read and action permissions. */
final class LegislatureWorkspace
{
    // The third argument remains compatible with existing callers. Speaker
    // navigation now opens a public preview; office actions keep their own gates.
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
            'sessions' => $base.'/sessions',
            'speaker' => $base.'/speaker',
            'maps' => $base.'/districts',
            'bills' => $base.'/bills',
            'committees' => $base.'/committees',
            'oversight' => $base.'/oversight',
            'referendums' => $base.'/referendums',
            'settings' => $base.'/settings',
            'rooms' => '/rooms/chamber/'.$id,
        ];
    }
}
