<?php

namespace App\Services\Demo;

/** A safe boundary between committed repair actions, not a failed world item. */
class SimRepairPaused extends \RuntimeException {}
