<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Lusen\Tests\Fixtures\Resources\WidgetResource;

final class WidgetController
{
    public function show(): WidgetResource
    {
        return new WidgetResource(null);
    }
}
