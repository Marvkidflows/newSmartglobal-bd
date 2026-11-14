<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * R2: a test that mass-assigns an attribute removed from $fillable would otherwise be a SILENT no-op (the original audit found 5 such
     * tests). With SSI_STRICT_MASS_ASSIGNMENT=1 (opt-in for the whole suite, so the first full run can enumerate any remaining offenders)
     * — or in any test that calls strictMassAssignment() / extends a class that does — Eloquent throws MassAssignmentException instead of
     * discarding. Production behaviour is NOT changed: this flag is only ever set from tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Model::preventSilentlyDiscardingAttributes((bool) env('SSI_STRICT_MASS_ASSIGNMENT', false));
    }

    protected function tearDown(): void
    {
        Model::preventSilentlyDiscardingAttributes(false);   // static flag: never leak into the next test
        parent::tearDown();
    }

    protected function strictMassAssignment(bool $on = true): void
    {
        Model::preventSilentlyDiscardingAttributes($on);
    }
}
