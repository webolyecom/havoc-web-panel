<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{DB, Response, Scope};

final class TrafficController extends Controller
{
    private function pick(): array
    {
        $doms = array_values(array_filter(Scope::domains($this->user()), fn($d) => $d['type'] !== 'alias'));
        $id = (int)$this->req->str('domain_id', '0');
        $d = null;
        foreach ($doms as $x) {
            if ($id ? (int)$x['id'] === $id : true) {
                $d = $x;
                break;
            }
        }
        if ($id && !$d) {
            $this->abort(404);                                   // not in scope
        }
        $days = (int)$this->req->str('days', '30');
        return [$doms, $d, in_array($days, [7, 30, 90, 365], true) ? $days : 30];
    }

    /** @return array<int,array> one row per day, zero-filled, oldest first */
    private function series(int $domainId, int $days): array
    {
        $from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $have = [];
        foreach (DB::all('SELECT * FROM traffic_daily WHERE domain_id = ? AND day >= ?', [$domainId, $from]) as $r) {
            $have[$r['day']] = $r;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = $have[$day] ?? ['day' => $day, 'bytes' => 0, 'hits' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 's5' => 0];
        }
        return $out;
    }

    public function index(): Response
    {
        [$doms, $d, $days] = $this->pick();
        $series = $d ? $this->series((int)$d['id'], $days) : [];
        return view('traffic/index', ['domains' => $doms, 'd' => $d, 'days' => $days, 'series' => $series]);
    }

    public function export(): Response
    {
        [, $d, $days] = $this->pick();
        if (!$d) {
            $this->abort(404);
        }
        $out = "day,bytes,hits,status_2xx,status_3xx,status_4xx,status_5xx\n";
        foreach ($this->series((int)$d['id'], $days) as $r) {
            $out .= implode(',', [$r['day'], $r['bytes'], $r['hits'], $r['s2'], $r['s3'], $r['s4'], $r['s5']]) . "\n";
        }
        return new Response($out, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="traffic-' . (int)$d['id'] . '-' . $days . 'd.csv"']);
    }
}
