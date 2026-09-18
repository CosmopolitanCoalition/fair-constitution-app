<?php

namespace Tests\Unit;

use App\Support\BindMountCheck;
use PHPUnit\Framework\TestCase;

/**
 * The Setup archive bind check (WoS demo box 2026-09-17): an archive on its
 * own data disk has a mountinfo field 4 BELOW that disk's mount point, so the
 * old equality test reported "apply pending" on an applied bind. Pure text,
 * no host, no database.
 */
class BindMountCheckTest extends TestCase
{
    private const APP = '101 90 8:1 /home/wos/fair-constitution-app /var/www/html rw,relatime - ext4 /dev/sda1 rw';

    public function test_a_folder_on_the_root_disk_matches_by_equality(): void
    {
        $info = [self::APP, '102 90 8:1 /srv/archive /archive rw,relatime - ext4 /dev/sda1 rw'];

        $this->assertTrue(BindMountCheck::applied($info, '/archive', '/srv/archive'));
        $this->assertTrue(BindMountCheck::applied($info, '/archive', '/srv/archive/'), 'a trailing slash in .env is the same folder');
    }

    public function test_a_folder_on_a_separate_disk_matches_below_the_disk_mount_point(): void
    {
        // The data disk is mounted at /mnt/data on the host; .env names the host path.
        $info = [self::APP, '103 90 8:33 /archive /archive rw,relatime - ext4 /dev/sdc1 rw'];

        $this->assertTrue(BindMountCheck::applied($info, '/archive', '/mnt/data/archive'));
    }

    public function test_a_half_applied_change_still_answers_false(): void
    {
        // .env was changed, the containers were not recreated: the default folder is still bound.
        $info = [self::APP, '104 90 8:1 /home/wos/fair-constitution-app/data/archive /archive rw,relatime - ext4 /dev/sda1 rw'];

        $this->assertFalse(BindMountCheck::applied($info, '/archive', '/mnt/data/archive'));
    }

    public function test_a_suffix_match_lands_on_a_path_boundary(): void
    {
        $info = [self::APP, '105 90 8:33 /archive /archive rw,relatime - ext4 /dev/sdc1 rw'];

        $this->assertFalse(BindMountCheck::applied($info, '/archive', '/mnt/data/old-archive'));
    }

    public function test_a_whole_disk_bind_is_applied_when_its_device_differs_from_the_application(): void
    {
        $other = [self::APP, '106 90 8:33 / /archive rw,relatime - ext4 /dev/sdc1 rw'];
        $same = [self::APP, '107 90 8:1 / /archive rw,relatime - ext4 /dev/sda1 rw'];

        $this->assertTrue(BindMountCheck::applied($other, '/archive', '/mnt/data'));
        $this->assertNull(BindMountCheck::applied($same, '/archive', '/mnt/data'), 'the same device cannot tell');
    }

    public function test_docker_desktop_and_a_missing_mount_answer_null(): void
    {
        $desktop = [self::APP, '108 90 0:50 /run/desktop/mnt/host/e/archive /archive rw - 9p drvfs rw'];
        $hostMnt = [self::APP, '109 90 0:50 /host_mnt/e/archive /archive rw - fakeowner grpcfuse rw'];

        $this->assertNull(BindMountCheck::applied($desktop, '/archive', '/e/archive'));
        $this->assertNull(BindMountCheck::applied($hostMnt, '/archive', '/e/archive'));
        $this->assertNull(BindMountCheck::applied([self::APP], '/archive', '/srv/archive'));
    }

    public function test_an_escaped_space_in_the_path_is_read_back(): void
    {
        $info = [self::APP, '110 90 8:33 /map\\040data /archive rw,relatime - ext4 /dev/sdc1 rw'];

        $this->assertTrue(BindMountCheck::applied($info, '/archive', '/mnt/disk/map data'));
    }

    public function test_a_later_mount_over_the_same_point_wins(): void
    {
        $info = [
            self::APP,
            '111 90 8:1 /home/wos/fair-constitution-app/data/archive /archive rw - ext4 /dev/sda1 rw',
            '112 111 8:33 /archive /archive rw - ext4 /dev/sdc1 rw',
        ];

        $this->assertTrue(BindMountCheck::applied($info, '/archive', '/mnt/data/archive'));
    }
}
