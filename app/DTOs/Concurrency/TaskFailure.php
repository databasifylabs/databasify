<?php

declare(strict_types=1);

namespace App\DTOs\Concurrency;

use Exception;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final readonly class TaskFailure
{
    /**
     * @param  class-string<Throwable>  $class
     */
    public function __construct(
        public string $class,
        public string $message,
        public int $code,
    ) {}

    public static function of(Throwable $exception): self
    {
        $code = $exception->getCode();

        return new self($exception::class, $exception->getMessage(), is_int($code) ? $code : 0);
    }

    public function toException(): Throwable
    {
        if (! is_subclass_of($this->class, Exception::class) || (new ReflectionMethod($this->class, '__construct'))->getDeclaringClass()->getName() !== Exception::class) {
            return new RuntimeException($this->message, $this->code);
        }

        return new ($this->class)($this->message, $this->code);
    }
}
