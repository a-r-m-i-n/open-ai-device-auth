<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Command;

use Armin\OpenAiDeviceAuth\Auth\AuthFileReader;
use Armin\OpenAiDeviceAuth\Http\OpenAiHttpClientFactory;
use Armin\OpenAiDeviceAuth\Http\RateLimitResetClient;
use Armin\OpenAiDeviceAuth\Model\AuthFile;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\RateLimitResetCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: self::NAME, description: 'Use one ChatGPT usage-limit reset credit using an existing auth.json.')]
final class ResetCommand extends Command
{
    public const string NAME = 'reset';

    public function __construct(
        private readonly ?AuthFileReader $authFileReader = null,
        private readonly ?RateLimitResetClient $rateLimitResetClient = null,
        private readonly ?ResetCreditsRenderer $resetCreditsRenderer = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('auth-file', 'a', InputOption::VALUE_REQUIRED, 'Path to an existing auth.json', './auth.json')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
            ->addOption('credit-id', null, InputOption::VALUE_REQUIRED, 'Reset credit to use; otherwise list credits and ask which one to use')
            ->addOption('request-id', null, InputOption::VALUE_REQUIRED, 'Idempotency ID; reuse the same ID and credit selection when retrying (default: new UUIDv4)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $requestId = null;
        $creditId = null;
        $consumeStarted = false;

        try {
            $format = (string) $input->getOption('format');
            if (!in_array($format, ['text', 'json'], true)) {
                throw new OpenAiDeviceAuthException('The --format option must be either text or json.');
            }

            $authFileReader = $this->authFileReader ?? new AuthFileReader();
            $authFile = $authFileReader->read((string) $input->getOption('auth-file'));
            if ($authFile->authMode !== 'chatgpt') {
                throw new OpenAiDeviceAuthException('ChatGPT authentication is required for reset credits.');
            }
            $requestId = $input->getOption('request-id');
            $creditId = $input->getOption('credit-id');
            if ($requestId !== null && trim($requestId) === '') {
                throw new OpenAiDeviceAuthException('The --request-id option must not be empty.');
            }
            if ($creditId !== null && trim($creditId) === '') {
                throw new OpenAiDeviceAuthException('The --credit-id option must not be empty.');
            }
            $client = $this->rateLimitResetClient ?? new RateLimitResetClient(OpenAiHttpClientFactory::create());
            $selectionIo = $format === 'json' ? $io->getErrorStyle() : $io;
            $creditId ??= $this->selectCreditId($input, $selectionIo, $client, $authFile);
            if ($creditId === null) {
                $selectionIo->writeln('Abort');

                return Command::SUCCESS;
            }
            $requestId ??= $this->createRequestId();

            // Emit before sending so the same logical attempt can be retried after a timeout.
            if ($output instanceof ConsoleOutputInterface) {
                $output->getErrorOutput()->writeln([
                    'Reset request ID: ' . $requestId,
                    'Reset credit ID: ' . $creditId,
                ], OutputInterface::OUTPUT_RAW);
            } elseif ($format === 'text') {
                $io->text('Reset request ID: ' . OutputFormatter::escape($requestId));
                $io->text('Reset credit ID: ' . OutputFormatter::escape($creditId));
            }

            $consumeStarted = true;
            $result = $client->consume($authFile->accessToken, $authFile->accountId, $requestId, $creditId);
            if ($format === 'json') {
                $data = $result->toArray();
                $data['requestId'] = $requestId;
                $data['creditId'] = $creditId;
                $output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            } else {
                $message = match ($result->code) {
                    RateLimitResetCode::Reset => sprintf('Usage reset successfully. Windows reset: %d.', $result->windowsReset),
                    RateLimitResetCode::AlreadyRedeemed => 'This request already reset usage successfully.',
                    RateLimitResetCode::NothingToReset => 'Your usage does not need a reset right now.',
                    RateLimitResetCode::NoCredit => 'That reset is no longer available. Run resets to see your current credits.',
                };
                if ($result->isSuccess()) {
                    $io->success($message);
                } else {
                    $io->warning($message);
                }
            }

            return $result->isSuccess() ? Command::SUCCESS : Command::FAILURE;
        } catch (OpenAiDeviceAuthException | \JsonException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());
            if ($consumeStarted) {
                $io->getErrorStyle()->text(sprintf(
                    'Retry this attempt with --request-id=%s --credit-id=%s.',
                    OutputFormatter::escape($requestId),
                    OutputFormatter::escape($creditId)
                ));
            }

            return Command::FAILURE;
        }
    }

    private function selectCreditId(InputInterface $input, SymfonyStyle $io, RateLimitResetClient $client, AuthFile $authFile): ?string
    {
        if (!$input->isInteractive()) {
            throw new OpenAiDeviceAuthException('The --credit-id option is required in non-interactive mode.');
        }

        $resets = $client->list($authFile->accessToken, $authFile->accountId);
        ($this->resetCreditsRenderer ?? new ResetCreditsRenderer())->render($io, $resets);
        if ($resets->availableCount === 0) {
            throw new OpenAiDeviceAuthException('No usage limit resets are available.');
        }

        $creditIds = [];
        foreach ($resets->credits as $credit) {
            if ($credit->status === 'available') {
                $creditIds[] = $credit->id;
            }
        }
        if ($creditIds === []) {
            throw new OpenAiDeviceAuthException('No available reset credit IDs were returned. Specify --credit-id explicitly.');
        }

        $question = new ChoiceQuestion('Which reset would you like to use? (ENTER to abort)', $creditIds);
        $validator = $question->getValidator();
        $question->setValidator(static fn (mixed $answer): ?string => trim((string) $answer) === '' ? null : $validator($answer));

        try {
            return $io->askQuestion($question);
        } catch (MissingInputException) {
            $io->newLine();

            return null;
        }
    }

    private function createRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
