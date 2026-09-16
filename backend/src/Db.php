<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function path(): string
    {
        $dir = getenv('DATA_DIR') ?: (dirname(__DIR__) . '/data');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . '/tideharm.db';
    }

    public static function conn(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $pdo = new PDO('sqlite:' . self::path());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function migrate(): void
    {
        $pdo = self::conn();
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS stations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT UNIQUE NOT NULL,
  name TEXT NOT NULL,
  datum_m REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS constituents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  speed_deg_per_hour REAL NOT NULL,
  amplitude_m REAL NOT NULL,
  phase_deg REAL NOT NULL,
  UNIQUE(station_id, name),
  FOREIGN KEY(station_id) REFERENCES stations(id)
);
CREATE TABLE IF NOT EXISTS observations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id INTEGER NOT NULL,
  t_hours REAL NOT NULL,
  level_m REAL NOT NULL,
  FOREIGN KEY(station_id) REFERENCES stations(id)
);
CREATE UNIQUE INDEX IF NOT EXISTS observations_station_time_uq
  ON observations(station_id, t_hours);
CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
SQL);
    }
}
