<?php

namespace ErnestDefoe\Depot\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * For store extensions: tell Depot who owns your products.
 *
 *     (new \ErnestDefoe\Depot\Extend\Products())->access(MyAccess::class)
 *
 * The class implements ProductAccess. Products themselves are written with
 * ProductCatalog, whenever the store's own catalogue changes.
 */
class Products implements ExtenderInterface
{
    /** @var class-string[] */
    private array $access = [];

    /** @param class-string $class */
    public function access(string $class): self
    {
        $this->access[] = $class;

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->extend('depot.product_access', fn (array $classes) => array_merge($classes, $this->access));
    }
}
