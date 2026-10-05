<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Command;

use Armin\OpenAiDeviceAuth\Model\RateLimitResetCreditsResponse;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ResetCreditsRenderer
{
    public function __construct(private readonly ?Closure $nowProvider = null)
    {
    }

    public function render(SymfonyStyle $io, RateLimitResetCreditsResponse $resets): void
    {
        $io->title('Usage limit resets');
        $io->text(sprintf('Available resets: %d', $resets->availableCount));
        if ($resets->credits === []) {
            $io->text('No reset credit details are available.');

            return;
        }

        $now = $this->nowProvider !== null
            ? ($this->nowProvider)()
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($resets->credits as $credit) {
            $statusColor = match ($credit->status) {
                'available' => 'green',
                'redeeming' => 'yellow',
                'redeemed' => '#666666',
                default => 'yellow',
            };
            $title = trim($credit->title ?? '') ?: 'Usage limit reset';

            $io->newLine();
            $io->writeln($this->color(str_repeat('─', 60), '#666666'));
            $io->writeln(sprintf(
                '<options=bold>%s</> %s',
                OutputFormatter::escape($title),
                $this->color('[' . $credit->status . ']', $statusColor)
            ));
            if (trim($credit->description ?? '') !== '') {
                $io->text(OutputFormatter::escape($credit->description));
            }
            $io->newLine();
            $io->writeln('  ID:      ' . $this->color($credit->id, 'cyan'));
            $io->writeln('  Granted: ' . $this->formatTimestamp($credit->grantedAt, $now));
            $io->writeln('  Expires: ' . ($credit->expiresAt === null
                ? $this->color('Does not expire', '#666666')
                : $this->formatTimestamp($credit->expiresAt, $now, true)));
            $io->writeln('  Type:    ' . $this->color($credit->resetType, '#666666'));
        }
        $io->newLine();
    }

    private function formatTimestamp(string $timestamp, DateTimeImmutable $now, bool $expiry = false): string
    {
        try {
            $date = new DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return OutputFormatter::escape($timestamp) . ' ' . $this->color('(relative time unavailable)', '#666666');
        }

        $seconds = $date->getTimestamp() - $now->getTimestamp();
        $relative = $this->formatRelativeTime($seconds);
        $color = '#666666';
        if ($expiry) {
            if ($seconds < 0) {
                $relative = 'expired ' . $relative;
                $color = 'red';
            } elseif ($seconds <= 86400) {
                $color = 'yellow';
            }
        }

        return sprintf(
            '%s %s',
            $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s \U\T\C'),
            $this->color('(' . $relative . ')', $color)
        );
    }

    private function formatRelativeTime(int $seconds): string
    {
        if ($seconds === 0) {
            return 'now';
        }

        $minutes = intdiv(abs($seconds), 60);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $duration = match (true) {
            $days > 0 => sprintf('%dd %dh', $days, $hours),
            $hours > 0 => sprintf('%dh %dm', $hours, $minutes % 60),
            $minutes > 0 => sprintf('%dm', $minutes),
            default => 'less than 1m',
        };

        return $seconds > 0 ? 'in ' . $duration : $duration . ' ago';
    }

    private function color(string $text, string $color): string
    {
        return sprintf('<fg=%s>%s</>', $color, OutputFormatter::escape($text));
    }
}
