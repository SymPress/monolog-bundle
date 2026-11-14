<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Fixtures;

// WordPress loads WP_List_Table only in the admin; the parent is deliberately missing in the tests.
final class AdminOnlyTable extends \SymPress_Missing_WP_List_Table
{
}
