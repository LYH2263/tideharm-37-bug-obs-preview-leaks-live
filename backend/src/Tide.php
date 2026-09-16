<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

final class Tide
{
    public static function listStations(PDO $db): array
    {
        $rows = $db->query('SELECT slug, name, datum_m FROM stations ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $res = self::residuals($db, $r['slug']);
            $out[] = [
                'slug' => $r['slug'],
                'name' => $r['name'],
                'datum_m' => (float)$r['datum_m'],
                'max_abs_residual_m' => $res['max_abs_residual_m'] ?? 0.0,
                'ok' => ($res['max_abs_residual_m'] ?? 0) <= self::threshold($db),
            ];
        }
        return $out;
    }

    public static function getStation(PDO $db, string $slug): ?array
    {
        $st = $db->prepare('SELECT * FROM stations WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        return [
            'slug' => $row['slug'],
            'name' => $row['name'],
            'datum_m' => (float)$row['datum_m'],
            'constituents' => self::listConstituents($db, $slug) ?? [],
        ];
    }

    public static function listConstituents(PDO $db, string $slug): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        $st = $db->prepare('SELECT name, speed_deg_per_hour, amplitude_m, phase_deg FROM constituents WHERE station_id = ? ORDER BY id');
        $st->execute([$id]);
        return array_map(static function ($r) {
            return [
                'name' => $r['name'],
                'speed_deg_per_hour' => (float)$r['speed_deg_per_hour'],
                'amplitude_m' => (float)$r['amplitude_m'],
                'phase_deg' => (float)$r['phase_deg'],
            ];
        }, $st->fetchAll());
    }

    public static function saveConstituents(PDO $db, string $slug, array $items): array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            throw new InvalidArgumentException('station not found');
        }
        if (!$items) {
            throw new InvalidArgumentException('items required');
        }
        foreach ($items as $it) {
            $amp = (float)($it['amplitude_m'] ?? -1);
            $spd = (float)($it['speed_deg_per_hour'] ?? 0);
            if ($amp < 0 || $spd <= 0 || empty($it['name'])) {
                throw new InvalidArgumentException('invalid constituent');
            }
        }
        $db->prepare('DELETE FROM constituents WHERE station_id = ?')->execute([$id]);
        $ins = $db->prepare('INSERT INTO constituents(station_id, name, speed_deg_per_hour, amplitude_m, phase_deg) VALUES (?,?,?,?,?)');
        foreach ($items as $it) {
            $ins->execute([
                $id,
                (string)$it['name'],
                (float)$it['speed_deg_per_hour'],
                (float)$it['amplitude_m'],
                (float)($it['phase_deg'] ?? 0),
            ]);
        }
        return ['items' => self::listConstituents($db, $slug)];
    }

    public static function levelAt(array $constituents, float $datum, float $tHours): float
    {
        $sum = $datum;
        foreach ($constituents as $c) {
            $rad = deg2rad($c['speed_deg_per_hour'] * $tHours + $c['phase_deg']);
            $sum += $c['amplitude_m'] * cos($rad);
        }
        return round($sum, 4);
    }

    public static function forecast(PDO $db, string $slug, int $hours, int $stepMin): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        $hours = max(1, min(168, $hours));
        $stepMin = max(5, min(120, $stepMin));
        $points = [];
        for ($m = 0; $m <= $hours * 60; $m += $stepMin) {
            $t = $m / 60.0;
            $points[] = ['t_hours' => round($t, 4), 'level_m' => self::levelAt($st['constituents'], $st['datum_m'], $t)];
        }
        return ['slug' => $slug, 'points' => $points];
    }

    public static function residuals(PDO $db, string $slug): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        $id = self::stationId($db, $slug);
        $q = $db->prepare('SELECT t_hours, level_m FROM observations WHERE station_id = ? ORDER BY t_hours');
        $q->execute([$id]);
        $obs = array_map(static fn($r) => [
            't_hours' => (float)$r['t_hours'],
            'level_m' => (float)$r['level_m'],
        ], $q->fetchAll());
        $thr = self::threshold($db);
        [$items, $maxAbs] = self::residualItems($st['constituents'], $st['datum_m'], $thr, $obs);
        return [
            'slug' => $slug,
            'threshold_m' => $thr,
            'max_abs_residual_m' => $maxAbs,
            'any_over' => $maxAbs > $thr,
            'items' => $items,
        ];
    }

    /**
     * 试算：用“完整的新观测集合”计算替换后的残差与相对旧集的增删改，不写库。
     */
    public static function previewObservations(PDO $db, string $slug, array $raw): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        $new = self::normalizeObservations($raw);
        $old = self::fetchObserved($db, $id);
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM observations WHERE station_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO observations(station_id, t_hours, level_m) VALUES (?,?,?)');
            foreach ($new as $p) {
                $ins->execute([$id, $p['t_hours'], $p['level_m']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return self::replacementResult($db, $slug, $new, $old);
    }

    /**
     * 确认：再次全量校验，任一非法整批拒绝（不写库）；全部合法才在事务内整批替换。
     */
    public static function replaceObservations(PDO $db, string $slug, array $raw): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        $new = self::normalizeObservations($raw);
        // 在改动前先捕获旧集，使确认响应里的增删改与试算口径一致。
        $old = self::fetchObserved($db, $id);
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM observations WHERE station_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO observations(station_id, t_hours, level_m) VALUES (?,?,?)');
            foreach ($new as $p) {
                $ins->execute([$id, $p['t_hours'], $p['level_m']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return self::replacementResult($db, $slug, $new, $old);
    }

    /**
     * 校验整批观测：非空列表；时刻、潮位均为有限数；时刻非负且不重复。
     * 任一不合法即抛 InvalidArgumentException，由调用方整批拒绝。
     */
    private static function normalizeObservations(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            throw new InvalidArgumentException('observations must be a non-empty list');
        }
        $out = [];
        foreach ($raw as $it) {
            if (!is_array($it)) {
                throw new InvalidArgumentException('each observation must be an object');
            }
            $t = $it['t_hours'] ?? null;
            $l = $it['level_m'] ?? null;
            if (!is_numeric($t) || !is_numeric($l)) {
                throw new InvalidArgumentException('t_hours and level_m must be numbers');
            }
            $t = (float)$t;
            $l = (float)$l;
            if (!is_finite($t) || !is_finite($l)) {
                throw new InvalidArgumentException('t_hours and level_m must be finite');
            }
            if ($t < 0) {
                throw new InvalidArgumentException('t_hours must not be negative');
            }
            foreach ($out as $p) {
                if ($p['t_hours'] === $t) {
                    throw new InvalidArgumentException('duplicate t_hours: ' . $t);
                }
            }
            $out[] = ['t_hours' => $t, 'level_m' => round($l, 4)];
        }
        usort($out, static fn($a, $b) => $a['t_hours'] <=> $b['t_hours']);
        return $out;
    }

    /**
     * 给定新观测集合（不依赖库中观测），计算替换后的残差结论，并与旧集比对增删改。
     * $old 为替换前的旧观测映射（时刻字符串 => 潮位），由调用方在写库前捕获。
     */
    private static function replacementResult(PDO $db, string $slug, array $new, array $old): array
    {
        $st = self::getStation($db, $slug);
        $thr = self::threshold($db);
        [$items, $maxAbs] = self::residualItems($st['constituents'], $st['datum_m'], $thr, $new);

        $newMap = [];
        foreach ($new as $p) {
            $newMap[(string)$p['t_hours']] = $p['level_m'];
        }

        $added = [];
        $removed = [];
        $changed = [];
        foreach ($new as $p) {
            $key = (string)$p['t_hours'];
            if (!array_key_exists($key, $old)) {
                $added[] = $p['t_hours'];
            } elseif ($old[$key] !== $p['level_m']) {
                $changed[] = [
                    't_hours' => $p['t_hours'],
                    'old_level_m' => $old[$key],
                    'new_level_m' => $p['level_m'],
                ];
            }
        }
        foreach ($old as $key => $level) {
            if (!array_key_exists($key, $newMap)) {
                $removed[] = (float)$key;
            }
        }
        sort($added);
        sort($removed);

        return [
            'slug' => $slug,
            'threshold_m' => $thr,
            'max_abs_residual_m' => $maxAbs,
            'any_over' => $maxAbs > $thr,
            'items' => $items,
            'diff' => [
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
        ];
    }

    /**
     * 纯函数：对给定观测集合逐点计算残差（观测−预报），返回 [各点, 最大绝对残差]。
     */
    private static function residualItems(array $constituents, float $datum, float $thr, array $obs): array
    {
        $items = [];
        $maxAbs = 0.0;
        foreach ($obs as $o) {
            $pred = self::levelAt($constituents, $datum, $o['t_hours']);
            $res = round($o['level_m'] - $pred, 4);
            $maxAbs = max($maxAbs, abs($res));
            $items[] = [
                't_hours' => $o['t_hours'],
                'observed_m' => $o['level_m'],
                'predicted_m' => $pred,
                'residual_m' => $res,
                'over' => abs($res) > $thr,
            ];
        }
        return [$items, round($maxAbs, 4)];
    }

    public static function settings(PDO $db): array
    {
        return ['residual_threshold_m' => self::threshold($db)];
    }

    public static function saveSettings(PDO $db, array $body): array
    {
        $v = (float)($body['residual_threshold_m'] ?? 0);
        if ($v <= 0 || $v > 5) {
            throw new InvalidArgumentException('residual_threshold_m out of range');
        }
        $db->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute(['residual_threshold_m', (string)$v]);
        return self::settings($db);
    }

    private static function threshold(PDO $db): float
    {
        $st = $db->query("SELECT value FROM settings WHERE key = 'residual_threshold_m'")->fetch();
        return $st ? (float)$st['value'] : 0.15;
    }

    private static function stationId(PDO $db, string $slug): ?int
    {
        $st = $db->prepare('SELECT id FROM stations WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ? (int)$row['id'] : null;
    }

    /**
     * 读取站内现有观测，映射为 时刻字符串 => 潮位（4 位小数），用于增删改比对。
     */
    private static function fetchObserved(PDO $db, int $id): array
    {
        $q = $db->prepare('SELECT t_hours, level_m FROM observations WHERE station_id = ?');
        $q->execute([$id]);
        $old = [];
        foreach ($q->fetchAll() as $r) {
            $old[(string)(float)$r['t_hours']] = round((float)$r['level_m'], 4);
        }
        return $old;
    }
}
