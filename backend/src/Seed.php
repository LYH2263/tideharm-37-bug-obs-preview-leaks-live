<?php
declare(strict_types=1);

namespace App;

final class Seed
{
    public static function run(): void
    {
        $db = Db::conn();
        $n = (int)$db->query('SELECT COUNT(*) AS c FROM stations')->fetch()['c'];
        if ($n > 0) {
            return;
        }
        $db->exec("INSERT INTO settings(key, value) VALUES('residual_threshold_m', '0.15')");

        // Station A: M2+S2 consistent with observations
        $db->exec("INSERT INTO stations(slug, name, datum_m) VALUES('harbor-a', '东港站', 1.20)");
        $idA = (int)$db->query("SELECT id FROM stations WHERE slug='harbor-a'")->fetch()['id'];
        $consA = [
            ['M2', 28.984104, 1.10, 30.0],
            ['S2', 30.0, 0.35, 10.0],
            ['K1', 15.041069, 0.25, 50.0],
        ];
        $insC = $db->prepare('INSERT INTO constituents(station_id, name, speed_deg_per_hour, amplitude_m, phase_deg) VALUES (?,?,?,?,?)');
        foreach ($consA as $c) {
            $insC->execute([$idA, $c[0], $c[1], $c[2], $c[3]]);
        }
        $insO = $db->prepare('INSERT INTO observations(station_id, t_hours, level_m) VALUES (?,?,?)');
        for ($h = 0; $h <= 24; $h += 3) {
            $lvl = Tide::levelAt([
                ['speed_deg_per_hour' => 28.984104, 'amplitude_m' => 1.10, 'phase_deg' => 30.0],
                ['speed_deg_per_hour' => 30.0, 'amplitude_m' => 0.35, 'phase_deg' => 10.0],
                ['speed_deg_per_hour' => 15.041069, 'amplitude_m' => 0.25, 'phase_deg' => 50.0],
            ], 1.20, (float)$h);
            $insO->execute([$idA, (float)$h, $lvl]);
        }

        // Station B: clean second harbor
        $db->exec("INSERT INTO stations(slug, name, datum_m) VALUES('harbor-b', '西湾站', 0.80)");
        $idB = (int)$db->query("SELECT id FROM stations WHERE slug='harbor-b'")->fetch()['id'];
        $consB = [
            ['M2', 28.984104, 0.90, 40.0],
            ['S2', 30.0, 0.28, 20.0],
        ];
        foreach ($consB as $c) {
            $insC->execute([$idB, $c[0], $c[1], $c[2], $c[3]]);
        }
        for ($h = 0; $h <= 24; $h += 3) {
            $lvl = Tide::levelAt([
                ['speed_deg_per_hour' => 28.984104, 'amplitude_m' => 0.90, 'phase_deg' => 40.0],
                ['speed_deg_per_hour' => 30.0, 'amplitude_m' => 0.28, 'phase_deg' => 20.0],
            ], 0.80, (float)$h);
            $insO->execute([$idB, (float)$h, $lvl]);
        }
    }
}
