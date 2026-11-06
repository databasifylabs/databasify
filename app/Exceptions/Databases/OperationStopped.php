<?php

declare(strict_types=1);

namespace App\Exceptions\Databases;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class OperationStopped extends RuntimeException implements ShouldntReport {}
