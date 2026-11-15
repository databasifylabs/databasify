<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use App\Actions\Setup\CreateOwnerAccount;
use App\Concerns\OwnerValidationRules;
use App\DTOs\Setup\NewOwner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;

use Symfony\Component\Console\Input\StreamableInputInterface;

#[Signature('setup:owner {--name= : The owner\'s name} {--email= : The owner\'s email address}')]
#[Description('Create the owner account; the password is read from standard input, or asked for on a terminal')]
final class CreateOwnerCommand extends Command
{
    use OwnerValidationRules;

    public function handle(CreateOwnerAccount $create): int
    {
        $password = $this->ownerPassword();

        try {
            $owner = $create->handle(NewOwner::fromInput(Validator::make([
                'name' => $this->option('name'),
                'email' => $this->option('email'),
                'password' => $password,
                'password_confirmation' => $password,
            ], $this->ownerRules())->safe()));
        } catch (ValidationException $exception) {
            collect($exception->validator->errors()->all())->each(fn (string $message) => $this->components->error($message));

            return self::FAILURE;
        }

        $this->components->info("Owner account {$owner->email} created. Setup is closed.");

        return self::SUCCESS;
    }

    private function ownerPassword(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= STDIN;

        if (stream_isatty($stream)) {
            return password('Owner password', required: true);
        }

        return Str::of(stream_get_contents($stream) ?: '')->rtrim("\r\n")->value();
    }
}
