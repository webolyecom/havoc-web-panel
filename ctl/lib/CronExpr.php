<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Standard 5-field cron expressions (Vixie/Debian cron semantics): numbers, '*', ranges a-b, lists, steps (*\/n, a-b/n),
 * the @hourly/@daily/@weekly/@monthly/@yearly macros, Sunday as 0 or 7. Used to validate schedules strictly (range checks)
 * and to compute upcoming run times for the UI. Shared by the privileged helper and the panel.
 */
final class CronExpr
{
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    private const NAMES  = ['minute', 'hour', 'day of month', 'month', 'day of week'];
    public const MACROS  = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0',
                            '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *'];

    /** @var array<int,array<int,bool>> allowed values per field */
    private array $sets = [];
    private bool $domStar;
    private bool $dowStar;
    public readonly string $expr;
    public readonly array $fields;

    private function __construct(string $expr)
    {
        $this->expr = $expr;
        $this->fields = explode(' ', self::MACROS[$expr] ?? $expr);
        if (count($this->fields) !== 5) {
            throw new \InvalidArgumentException('A schedule needs exactly 5 fields: minute hour day month weekday');
        }
        foreach ($this->fields as $i => $f) {
            $this->sets[$i] = self::field($f, $i);
        }
        if (isset($this->sets[4][7])) {                          // 7 = Sunday
            unset($this->sets[4][7]);
            $this->sets[4][0] = true;
        }
        $this->domStar = $this->fields[2][0] === '*';
        $this->dowStar = $this->fields[4][0] === '*';
    }

    public static function parse(string $expr): self
    {
        $expr = trim(preg_replace('/\s+/', ' ', strtolower($expr)));
        if ($expr === '' || strlen($expr) > 120) {
            throw new \InvalidArgumentException('Invalid cron schedule');
        }
        return new self($expr);
    }

    private static function field(string $f, int $i): array
    {
        [$min, $max] = self::RANGES[$i];
        $out = [];
        foreach (explode(',', $f) as $part) {
            if (!preg_match('/^(\*|(\d{1,2})(?:-(\d{1,2}))?)(?:\/(\d{1,2}))?$/', $part, $m)) {
                throw new \InvalidArgumentException('Invalid ' . self::NAMES[$i] . ' field: ' . $part);
            }
            if ($m[1] === '*') {
                [$a, $b] = [$min, $max];
            } else {
                $a = (int)$m[2];
                $b = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : $a;
                if (isset($m[4]) && $m[4] !== '' && (!isset($m[3]) || $m[3] === '')) {
                    $b = $max;                                   // "5/15" means 5-max/15, as in Vixie cron
                }
            }
            $step = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 1;
            if ($a < $min || $b > $max || $a > $b || $step < 1 || $step > $max) {
                throw new \InvalidArgumentException('Out of range ' . self::NAMES[$i] . ' value: ' . $part . " (allowed $min-$max)");
            }
            for ($v = $a; $v <= $b; $v += $step) {
                $out[$v] = true;
            }
        }
        return $out;
    }

    private function dayMatches(\DateTimeImmutable $t): bool
    {
        $dom = isset($this->sets[2][(int)$t->format('j')]);
        $dow = isset($this->sets[4][(int)$t->format('w')]);
        if ($this->domStar || $this->dowStar) {
            return ($this->domStar ? true : $dom) && ($this->dowStar ? true : $dow);
        }
        return $dom || $dow;                                     // both restricted: either one (cron's OR rule)
    }

    /** @return \DateTimeImmutable[] the next $count run times strictly after $from (in $from's time zone) */
    public function next(\DateTimeImmutable $from, int $count = 1): array
    {
        $out = [];
        $hours = array_keys($this->sets[1]);
        $mins = array_keys($this->sets[0]);
        sort($hours);
        sort($mins);
        $t = $from->setTime((int)$from->format('G'), (int)$from->format('i'))->modify('+1 minute');
        $day = $t->setTime(0, 0);
        for ($d = 0; $d < 366 * 5 && count($out) < $count; $d++, $day = $day->modify('+1 day')) {
            if (!isset($this->sets[3][(int)$day->format('n')]) || !$this->dayMatches($day)) {
                continue;
            }
            $sameDay = $day->format('Y-m-d') === $t->format('Y-m-d');
            foreach ($hours as $h) {
                if ($sameDay && $h < (int)$t->format('G')) {
                    continue;
                }
                foreach ($mins as $mi) {
                    if ($sameDay && $h === (int)$t->format('G') && $mi < (int)$t->format('i')) {
                        continue;
                    }
                    $out[] = $day->setTime($h, $mi);
                    if (count($out) >= $count) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * A structured description of common patterns, so the UI can phrase it in any language.
     * @return array{0:string,1:array} [kind, params]; kind 'custom' when no simple phrase fits
     */
    public function describe(): array
    {
        [$mi, $h, $dom, $mon, $dow] = $this->fields;
        $num = static fn(string $s) => ctype_digit($s);
        $time = static fn() => sprintf('%02d:%02d', (int)$h, (int)$mi);
        if ($mon !== '*') {
            return ['custom', []];
        }
        if ($mi === '*' && $h === '*' && $dom === '*' && $dow === '*') {
            return ['every_minute', []];
        }
        if (preg_match('/^\*\/(\d+)$/', $mi, $m) && $h === '*' && $dom === '*' && $dow === '*') {
            return ['every_n_minutes', ['n' => (int)$m[1]]];
        }
        if ($num($mi) && $h === '*' && $dom === '*' && $dow === '*') {
            return ['hourly', ['m' => sprintf('%02d', (int)$mi)]];
        }
        if ($num($mi) && preg_match('/^\*\/(\d+)$/', $h, $m) && $dom === '*' && $dow === '*') {
            return ['every_n_hours', ['n' => (int)$m[1], 'm' => sprintf('%02d', (int)$mi)]];
        }
        if ($num($mi) && $num($h) && $dom === '*' && $dow === '*') {
            return ['daily', ['time' => $time()]];
        }
        if ($num($mi) && $num($h) && $dom === '*' && preg_match('/^[0-7](,[0-7])*$/', $dow)) {
            $days = array_values(array_unique(array_map(static fn($x) => (int)$x % 7, explode(',', $dow))));
            return ['weekly', ['days' => $days, 'time' => $time()]];
        }
        if ($num($mi) && $num($h) && $num($dom) && $dow === '*') {
            return ['monthly', ['day' => (int)$dom, 'time' => $time()]];
        }
        return ['custom', []];
    }
}
