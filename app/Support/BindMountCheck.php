<?php

namespace App\Support;

/**
 * BindMountCheck — does the bind mounted at a container path come from the
 * host folder named in .env? Pure text over /proc/self/mountinfo, so it is
 * testable with no host.
 *
 * A mountinfo line reads (man 5 proc):
 *
 *   36 35 98:0 /mnt1 /mnt2 rw,noatime master:1 - ext3 /dev/root rw,errors=continue
 *   (1)(2)(3)   (4)   (5)      (6)      (7)   (8) (9)   (10)         (11)
 *
 * Field 4 is the bind's root INSIDE its source filesystem, not the host path.
 * On a host whose folder sits on the root disk the two are equal. On a host
 * whose folder sits on a SEPARATE disk (WoS demo box 2026-09-17: the archive on
 * a data disk mounted at /mnt/data) field 4 is the path below that disk's own
 * mount point: .env says /mnt/data/archive, field 4 says /archive. The old
 * equality test answered "not applied" for that host until data landed.
 *
 * The identity rule: the bind is applied when the .env path ENDS WITH field 4
 * at a path boundary (equal on the root disk; the remainder is the disk's mount
 * point on a separate disk). A bind of a whole disk has field 4 = "/", which
 * names no folder: it is applied when the mount's device differs from the
 * device behind the application folder (the default ./data/archive always
 * shares the application's device), and unknown otherwise. A half-applied
 * change still answers false: the old bind's field 4 is the old folder, which
 * is no suffix of the new path.
 */
class BindMountCheck
{
    /**
     * @param  list<string>  $mountinfo   lines of /proc/self/mountinfo
     * @param  string  $mountPoint        the container path (e.g. /archive)
     * @param  string  $envPath           the absolute host path from .env
     * @param  string  $appMountPoint     a container path bound from the application folder
     * @return bool|null true = applied, false = a different folder is bound, null = cannot tell
     */
    public static function applied(array $mountinfo, string $mountPoint, string $envPath, string $appMountPoint = '/var/www/html'): ?bool
    {
        $mount = self::find($mountinfo, $mountPoint);
        if ($mount === null) {
            return null;
        }

        $root = $mount['root'];
        if (str_starts_with($root, '/run/desktop/') || str_starts_with($root, '/host_mnt/')) {
            return null;                         // Docker Desktop: a VM path, not comparable
        }

        $env = rtrim($envPath, '/');
        $root = rtrim($root, '/');

        if ($root === '') {
            // The whole disk is bound. Same device as the application folder = cannot tell.
            $app = self::find($mountinfo, $appMountPoint);
            if ($app === null || $mount['device'] === '' || $app['device'] === '') {
                return null;
            }

            return $mount['device'] !== $app['device'] ? true : null;
        }

        // $root starts with "/", so a suffix match always lands on a path boundary.
        return $env === $root || str_ends_with($env, $root);
    }

    /**
     * The mountinfo entry for a container path: its field-4 root and its
     * device (field 3, major:minor). The LAST matching line wins (a later
     * mount over the same point shadows an earlier one).
     *
     * @param  list<string>  $mountinfo
     * @return array{root: string, device: string}|null
     */
    public static function find(array $mountinfo, string $mountPoint): ?array
    {
        $found = null;
        foreach ($mountinfo as $line) {
            $f = preg_split('/\s+/', trim((string) $line)) ?: [];
            if (($f[4] ?? '') !== $mountPoint) {
                continue;
            }
            $found = [
                'root'   => self::unescape((string) ($f[3] ?? '')),
                'device' => (string) ($f[2] ?? ''),
            ];
        }

        return $found;
    }

    /** mountinfo escapes space, tab, newline and backslash as octal. */
    private static function unescape(string $path): string
    {
        return strtr($path, ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
    }
}
