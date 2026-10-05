<?php

declare(strict_types=1);

namespace Armin\OpenAiDeviceAuth\Tests\Command;

use Armin\OpenAiDeviceAuth\Command\ResetCreditsRenderer;
use Armin\OpenAiDeviceAuth\Model\RateLimitResetCredit;
use Armin\OpenAiDeviceAuth\Model\RateLimitResetCreditsResponse;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ResetCreditsRendererTest extends TestCase
{
    #[DataProvider('expiryDates')]
    public function testItShowsAbsoluteUtcDatesAndRelativeTimes(?string $expiresAt, string $expected): void
    {
        $display = $this->render([
            new RateLimitResetCredit('credit-1', 'codex_rate_limits', 'available', '2026-06-17T02:00:00+02:00', $expiresAt),
        ]);

        self::assertStringContainsString('Granted: 2026-06-17 00:00:00 UTC (18d 12h ago)', $display);
        self::assertStringContainsString('Expires: ' . $expected, $display);
    }

    public static function expiryDates(): iterable
    {
        yield 'future' => ['2026-07-17T00:00:00Z', '2026-07-17 00:00:00 UTC (in 11d 12h)'];
        yield 'expired' => ['2026-07-05T10:00:00Z', '2026-07-05 10:00:00 UTC (expired 2h 0m ago)'];
        yield 'now in another timezone' => ['2026-07-05T14:00:00+02:00', '2026-07-05 12:00:00 UTC (now)'];
        yield 'minutes' => ['2026-07-05T12:15:00Z', '2026-07-05 12:15:00 UTC (in 15m)'];
        yield 'less than a minute' => ['2026-07-05T12:00:30Z', '2026-07-05 12:00:30 UTC (in less than 1m)'];
        yield 'no expiry' => [null, 'Does not expire'];
        yield 'invalid timestamp' => ['not-a-date', 'not-a-date (relative time unavailable)'];
    }

    public function testInvalidGrantDatesDoNotHideTheRestOfTheCredits(): void
    {
        $display = $this->render([
            new RateLimitResetCredit('credit-1', 'codex_rate_limits', 'available', 'invalid-date'),
            new RateLimitResetCredit('credit-2', 'codex_rate_limits', 'redeemed', '2026-07-05T12:00:00Z'),
        ]);

        self::assertStringContainsString('Granted: invalid-date (relative time unavailable)', $display);
        self::assertStringContainsString('credit-2', $display);
        self::assertStringContainsString('Granted: 2026-07-05 12:00:00 UTC (now)', $display);
    }

    /** @param list<RateLimitResetCredit> $credits */
    private function render(array $credits): string
    {
        $output = new BufferedOutput();
        $renderer = new ResetCreditsRenderer(static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-05T12:00:00Z'));
        $renderer->render(new SymfonyStyle(new ArrayInput([]), $output), new RateLimitResetCreditsResponse(1, $credits));

        return $output->fetch();
    }
}
