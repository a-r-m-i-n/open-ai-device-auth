<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Command;

use Armin\OpenAiDeviceAuth\Auth\AuthFileReader;
use Armin\OpenAiDeviceAuth\Auth\AuthFileWriter;
use Armin\OpenAiDeviceAuth\Auth\TokenPayloadDecoder;
use Armin\OpenAiDeviceAuth\Http\OpenAiHttpClientFactory;
use Armin\OpenAiDeviceAuth\Http\RefreshTokenClient;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: self::NAME, description: 'Refresh OpenAI ChatGPT tokens in an existing auth.json.')]
final class RefreshCommand extends Command
{
    public const string NAME = 'refresh';

    public function __construct(
        private readonly ?AuthFileReader $authFileReader = null,
        private readonly ?RefreshTokenClient $refreshTokenClient = null,
        private readonly ?TokenPayloadDecoder $tokenPayloadDecoder = null,
        private readonly ?AuthFileWriter $authFileWriter = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('auth-file', 'a', InputOption::VALUE_REQUIRED, 'Path to an existing auth.json', './auth.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $authFilePath = (string) $input->getOption('auth-file');
            $httpClient = OpenAiHttpClientFactory::create();
            $authFileReader = $this->authFileReader ?? new AuthFileReader();
            $refreshTokenClient = $this->refreshTokenClient ?? new RefreshTokenClient($httpClient);
            $tokenPayloadDecoder = $this->tokenPayloadDecoder ?? new TokenPayloadDecoder();
            $authFileWriter = $this->authFileWriter ?? new AuthFileWriter();

            $authFile = $authFileReader->read($authFilePath);
            $tokens = $refreshTokenClient->refresh($authFile->refreshToken);
            $accountId = $tokenPayloadDecoder->extractAccountId($tokens);
            $authFileWriter->write($authFilePath, $tokens, $accountId);

            $io->success(sprintf('Tokens refreshed successfully in %s', $authFilePath));

            return Command::SUCCESS;
        } catch (OpenAiDeviceAuthException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
