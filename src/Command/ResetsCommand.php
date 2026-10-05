<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Command;

use Armin\OpenAiDeviceAuth\Auth\AuthFileReader;
use Armin\OpenAiDeviceAuth\Http\OpenAiHttpClientFactory;
use Armin\OpenAiDeviceAuth\Http\RateLimitResetClient;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: self::NAME, description: 'List ChatGPT usage-limit reset credits using an existing auth.json.')]
final class ResetsCommand extends Command
{
    public const string NAME = 'resets';

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
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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
            $client = $this->rateLimitResetClient ?? new RateLimitResetClient(OpenAiHttpClientFactory::create());
            $resets = $client->list($authFile->accessToken, $authFile->accountId);

            if ($format === 'json') {
                $output->writeln(json_encode($resets->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

                return Command::SUCCESS;
            }

            ($this->resetCreditsRenderer ?? new ResetCreditsRenderer())->render($io, $resets);

            return Command::SUCCESS;
        } catch (OpenAiDeviceAuthException | \JsonException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
