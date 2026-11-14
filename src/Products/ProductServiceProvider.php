<?php

namespace ErnestDefoe\Depot\Products;

use Flarum\Foundation\AbstractServiceProvider;

class ProductServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // The list store extensions extend with their ProductAccess classes.
        $this->container->singleton('depot.product_access', fn () => []);
    }
}
