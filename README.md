# 09-tideharm（潮谐）

港口潮位调和预报台。用分潮振幅与迟角合成潮位过程线，对照实测点看残差。

## 启动

```bash
docker compose up --build
```

| 入口 | 地址 |
| --- | --- |
| 前端 | http://localhost:3800 |
| API | http://localhost:8800 |

## 主链

选港口站 → 维护分潮 → 合成预报曲线 → 对照实测看残差表。

## 技术栈

PHP 8 + SQLite；Vue 3 + Vite。
