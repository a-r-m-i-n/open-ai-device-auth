<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Command;

use Armin\OpenAiDeviceAuth\Auth\AuthFileReader;
use Armin\OpenAiDeviceAuth\Http\OpenAiHttpClientFactory;
use Armin\OpenAiDeviceAuth\Http\UsageClient;
use Armin\OpenAiDeviceAuth\Model\OpenAiDeviceAuthException;
use Armin\OpenAiDeviceAuth\Model\UsageResponse;
use Armin\OpenAiDeviceAuth\Model\UsageWindow;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: self::NAME, description: 'Fetch ChatGPT usage and rate limits using an existing auth.json.')]
final class UsageCommand extends Command
{
    public const string NAME = 'usage';

    public function __construct(
        private readonly ?AuthFileReader $authFileReader = null,
        private readonly ?UsageClient $usageClient = null,
        private readonly ?Closure $nowProvider = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('auth-file', 'a', InputOption::VALUE_REQUIRED, 'Path to an existing auth.json', './auth.json')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: text, json or bars (default)', 'bars');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $authFilePath = (string) $input->getOption('auth-file');
            $format = (string) $input->getOption('format');
            if (!in_array($format, ['text', 'json', 'bars'], true)) {
                throw new OpenAiDeviceAuthException('The --format option must be either text, json or bars.');
            }

            $httpClient = OpenAiHttpClientFactory::create();
            $authFileReader = $this->authFileReader ?? new AuthFileReader();
            $usageClient = $this->usageClient ?? new UsageClient($httpClient);

            $authFile = $authFileReader->read($authFilePath);
            $usage = $usageClient->fetch($authFile->accessToken, $io);

            if ($format === 'json') {
                $json = json_encode($usage->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new OpenAiDeviceAuthException('Failed to encode usage payload.');
                }

                $output->writeln($json);

                return Command::SUCCESS;
            }

            if ($format === 'bars') {
                $this->renderBarsUsage($io, $usage, $authFilePath);

                return Command::SUCCESS;
            }

            $this->renderTextUsage($io, $usage, $authFilePath);

            return Command::SUCCESS;
        } catch (OpenAiDeviceAuthException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function renderTextUsage(SymfonyStyle $io, UsageResponse $usage, string $authFilePath): void
    {
        $primaryLeft = max(0, 100 - $usage->primary->usedPercent);
        $io->title('ChatGPT Usage');
        $io->writeln($authFilePath);
        $io->newLine();
        $io->definitionList(
            ['Primary Left' => $this->formatLeftPercent($primaryLeft)],
            ['Primary Used' => $this->formatUsedPercent($usage->primary->usedPercent)],
            ['Primary Window' => sprintf('%d minutes (%.1f hours)', $usage->primary->windowDurationMins, $usage->primary->windowDurationMins / 60)],
            ['Primary Resets' => $usage->primary->resetsAt],
            ['Primary Resets In' => $this->formatResetsIn($usage->primary)],
        );

        if ($usage->secondary !== null) {
            $secondaryLeft = max(0, 100 - $usage->secondary->usedPercent);
            $io->definitionList(
                ['Secondary Left' => $this->formatLeftPercent($secondaryLeft)],
                ['Secondary Used' => $this->formatUsedPercent($usage->secondary->usedPercent)],
                ['Secondary Window' => sprintf('%d minutes (%.1f days)', $usage->secondary->windowDurationMins, $usage->secondary->windowDurationMins / 1440)],
                ['Secondary Resets' => $usage->secondary->resetsAt],
                ['Secondary Resets In' => $this->formatResetsIn($usage->secondary)],
            );
        }

        if ($usage->rateLimitReachedType !== null) {
            $io->text(sprintf('Rate limit reached type: %s', $usage->rateLimitReachedType));
        }
    }

    private function renderBarsUsage(SymfonyStyle $io, UsageResponse $usage, string $authFilePath): void
    {
        $io->title('ChatGPT Usage');
        $io->writeln($authFilePath);
        $io->newLine();
        $io->writeln($this->formatUsageBarLine($usage->primary));

        if ($usage->secondary !== null) {
            $io->writeln($this->formatUsageBarLine($usage->secondary));
        }

        if ($usage->rateLimitReachedType !== null) {
            $io->newLine();
            $io->text(sprintf('Rate limit reached type: %s', $usage->rateLimitReachedType));
        }
        $io->newLine();
    }

    private function formatLeftPercent(float $leftPercent): string
    {
        return $this->formatPercentWithThresholds($leftPercent, 25.0, 5.0, false);
    }

    private function formatUsedPercent(float $usedPercent): string
    {
        return $this->formatPercentWithThresholds($usedPercent, 75.0, 95.0, true);
    }

    private function formatPercentWithThresholds(float $percent, float $warningThreshold, float $criticalThreshold, bool $higherIsWorse): string
    {
        $formattedPercent = sprintf('%.2f%%', $percent);
        $isCritical = $higherIsWorse ? $percent >= $criticalThreshold : $percent <= $criticalThreshold;
        if ($isCritical) {
            return sprintf('<fg=red>%s</>', $formattedPercent);
        }

        $isWarning = $higherIsWorse ? $percent >= $warningThreshold : $percent <= $warningThreshold;
        if ($isWarning) {
            return sprintf('<fg=#ffa500>%s</>', $formattedPercent);
        }

        return $formattedPercent;
    }

    private function formatResetsIn(UsageWindow $window): string
    {
        $resetAt = new DateTimeImmutable($window->resetsAt);
        $now = $this->nowProvider !== null
            ? ($this->nowProvider)()
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $secondsLeft = max(0, $resetAt->getTimestamp() - $now->getTimestamp());
        $minutesLeft = intdiv($secondsLeft, 60);
        if ($minutesLeft === 0) {
            return '0m';
        }

        $days = intdiv($minutesLeft, 1440);
        $hours = intdiv($minutesLeft % 1440, 60);
        $minutes = $minutesLeft % 60;

        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        }

        return sprintf('%dh %dm', $hours, $minutes);
    }

    private function formatUsageBarLine(UsageWindow $window): string
    {
        $leftPercent = max(0.0, 100.0 - $window->usedPercent);
        $roundedLeftPercent = (int) round($leftPercent);
        $style = $this->getThresholdStyle($leftPercent, 25.0, 10.0, false);
        $label = $this->formatWindowLabel($window);
        $bar = $this->buildProgressBar($leftPercent, 24, $style);
        $percent = $this->applyStyle(sprintf('%d%% left', $roundedLeftPercent), $style);

        return sprintf(
            '%s %s %s (reset in %s)',
            str_pad($label, 2, ' ', STR_PAD_RIGHT),
            $bar,
            $percent,
            $this->formatResetCountdownForBars($window)
        );
    }

    private function formatWindowLabel(UsageWindow $window): string
    {
        if ($window->windowDurationMins % 1440 === 0) {
            return sprintf('%dd', intdiv($window->windowDurationMins, 1440));
        }

        if ($window->windowDurationMins % 60 === 0) {
            return sprintf('%dh', intdiv($window->windowDurationMins, 60));
        }

        return sprintf('%dm', $window->windowDurationMins);
    }

    private function buildProgressBar(float $percent, int $width, ?string $style): string
    {
        $filled = (int) round(($percent / 100) * $width);
        $filled = max(0, min($width, $filled));

        $filledSegment = str_repeat('█', $filled);
        $emptySegment = str_repeat('░', $width - $filled);

        if ($filledSegment !== '') {
            $filledSegment = $this->applyStyle($filledSegment, $style ?? 'green');
        }

        if ($emptySegment !== '') {
            $emptySegment = $this->applyStyle($emptySegment, 'gray');
        }

        return sprintf(
            '%s%s%s',
            $this->applyStyle('[', $style ?? 'green'),
            $filledSegment . $emptySegment,
            $this->applyStyle(']', $style ?? 'green')
        );
    }

    private function formatResetCountdownForBars(UsageWindow $window): string
    {
        $resetAt = new DateTimeImmutable($window->resetsAt);
        $now = $this->nowProvider !== null
            ? ($this->nowProvider)()
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $secondsLeft = max(0, $resetAt->getTimestamp() - $now->getTimestamp());
        $minutesLeft = intdiv($secondsLeft, 60);
        if ($minutesLeft === 0) {
            return '0min';
        }

        $days = intdiv($minutesLeft, 1440);
        $hours = intdiv($minutesLeft % 1440, 60);
        $minutes = $minutesLeft % 60;

        if ($days > 0) {
            return $hours > 0
                ? sprintf('%dd %dh', $days, $hours)
                : sprintf('%dd', $days);
        }

        return sprintf('%dh %dmin', $hours, $minutes);
    }

    private function getThresholdStyle(float $percent, float $warningThreshold, float $criticalThreshold, bool $higherIsWorse): ?string
    {
        $isCritical = $higherIsWorse ? $percent >= $criticalThreshold : $percent <= $criticalThreshold;
        if ($isCritical) {
            return 'red';
        }

        $isWarning = $higherIsWorse ? $percent >= $warningThreshold : $percent <= $warningThreshold;
        if ($isWarning) {
            return '#ffa500';
        }

        return null;
    }

    private function applyStyle(string $text, ?string $style): string
    {
        if ($style === null) {
            return $text;
        }

        return sprintf('<fg=%s>%s</>', $style, $text);
    }
}
