<?php

namespace Tests\Unit;

use App\Http\Controllers\Organizations\CgcController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionUnionType;

/**
 * Pin (W-0447 finding, 2026-09-15): CgcController::show hands a non-CGC
 * organization back to the organization page with Inertia::location(), which
 * is a Symfony response, not an Inertia\Response. A return type that admits
 * only Inertia\Response turned that hand-off into a 500 on a valid id. DB-free.
 */
class CgcShowReturnTypeTest extends TestCase
{
    #[Test]
    public function show_admits_the_location_hand_off(): void
    {
        $type = (new ReflectionMethod(CgcController::class, 'show'))->getReturnType();
        $this->assertInstanceOf(ReflectionUnionType::class, $type);
        $names = array_map(static fn ($t) => $t->getName(), $type->getTypes());
        $this->assertContains(\Inertia\Response::class, $names);
        $this->assertContains(\Symfony\Component\HttpFoundation\Response::class, $names);
    }
}
