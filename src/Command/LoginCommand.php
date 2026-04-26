<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Command;

use Armin\OpenAiDeviceAuth\Auth\AuthFileWriter;
use Armin\OpenAiDeviceAuth\Auth\TokenPayloadDecoder;
use Armin\OpenAiDeviceAuth\Http\DeviceCodeClient;
use Armin\OpenAiDeviceAuth\Http\DeviceCodePoller;
use Armin\OpenAiDeviceAuth\Http\OpenAiHttpClientFactory;
use Armin\OpenAiDeviceAuth\Http\TokenExchanger;
use Armin\OpenAiDeviceAuth\Model\AuthorizationPendingException;
use Armin\OpenAiDeviceAuth\Model\DeviceCodeResponse;
use Armin\OpenAiDeviceAuth\Model\DeviceCodeResult;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: self::NAME, description: 'Authenticate via OpenAI device code flow and write auth.json.')]
final class LoginCommand extends Command
{
    public const NAME = 'login';

    public function __construct(
        private readonly ?DeviceCodeClient $deviceCodeClient = null,
        private readonly ?DeviceCodePoller $poller = null,
        private readonly ?TokenExchanger $tokenExchanger = null,
        private readonly ?TokenPayloadDecoder $tokenPayloadDecoder = null,
        private readonly ?AuthFileWriter $authFileWriter = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output path for auth.json', './auth.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $httpClient = OpenAiHttpClientFactory::create();
        $deviceCodeClient = $this->deviceCodeClient ?? new DeviceCodeClient($httpClient);
        $poller = $this->poller ?? new DeviceCodePoller($httpClient);
        $tokenExchanger = $this->tokenExchanger ?? new TokenExchanger($httpClient);
        $tokenPayloadDecoder = $this->tokenPayloadDecoder ?? new TokenPayloadDecoder();
        $authFileWriter = $this->authFileWriter ?? new AuthFileWriter();

        $io->title('OpenAI Device Code Authentication');
        $io->text('Requesting device code...');

        try {
            $deviceCode = $deviceCodeClient->requestUserCode();
            $this->renderDeviceCodeInstructions($io, $deviceCode);

            $result = $this->pollUntilAuthorized($io, $poller, $deviceCode);
            $io->newLine(2);
            $io->text('Authorization received. Exchanging tokens...');

            $tokens = $tokenExchanger->exchange($result);
            $accountId = $tokenPayloadDecoder->extractAccountId($tokens);
            $targetPath = (string) $input->getOption('output');

            $authFileWriter->write($targetPath, $tokens, $accountId);

            $io->success(sprintf('Authentication successful. Tokens written to %s', $targetPath));

            return Command::SUCCESS;
        } catch (OpenAiDeviceAuthException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function renderDeviceCodeInstructions(SymfonyStyle $io, DeviceCodeResponse $deviceCode): void
    {
        $io->section('Follow these steps');
        $io->writeln('1. Open this URL in your browser:');
        $io->writeln(sprintf('   <info>%s</info>', DeviceCodeClient::VERIFICATION_URL));
        $io->newLine();
        $io->writeln('2. Enter this one-time code:');
        $io->writeln(sprintf('   <info>%s</info>', $deviceCode->userCode));
        $io->newLine();
        $io->warning('Device codes are a common phishing target. Never share this code.');
        $io->write('Waiting for authorization');
    }

    private function pollUntilAuthorized(
        SymfonyStyle $io,
        DeviceCodePoller $poller,
        DeviceCodeResponse $deviceCode
    ): DeviceCodeResult {
        $deadline = time() + DeviceCodePoller::TIMEOUT_SECONDS;

        while (time() < $deadline) {
            try {
                $result = $poller->poll($deviceCode);
                $io->writeln('.');

                return $result;
            } catch (AuthorizationPendingException) {
                $io->write('.');
            }
        }

        throw new OpenAiDeviceAuthException('Timeout after 15 minutes waiting for authorization.');
    }
}
