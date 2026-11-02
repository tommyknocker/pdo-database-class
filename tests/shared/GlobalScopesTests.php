<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\query\GlobalScopes;

final class GlobalScopesTests extends TestCase
{
    public function testEnabledScopesAreAppliedOnceAndDisabledOnesNever(): void
    {
        $scopes = new GlobalScopes();
        $scope = static fn () => null;
        $scopes->set(['tenant' => $scope, 'active' => $scope, 'soft' => $scope]);
        $scopes->disable('active');
        $scopes->disableMany(['soft']);

        $applied = 0;
        $scopes->applyOnce(function () use (&$applied): void {
            $applied++;
        });
        $scopes->applyOnce(function () use (&$applied): void {
            $applied++;
        });

        $this->assertSame(1, $applied);
        $this->assertTrue($scopes->isDisabled('active'));
        $this->assertFalse($scopes->isDisabled('tenant'));
        $this->assertSame(['active', 'soft'], $scopes->disabled());
    }

    public function testNothingIsAppliedWithoutScopes(): void
    {
        $called = false;
        (new GlobalScopes())->applyOnce(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }
}
