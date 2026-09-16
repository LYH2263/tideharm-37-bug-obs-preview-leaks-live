<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { getJSON, sendJSON } from '../api'

type EditRow = { t_hours: number | ''; level_m: number | '' }
type ResRow = { t_hours: number; observed_m: number; predicted_m: number; residual_m: number; over: boolean }
type Changed = { t_hours: number; old_level_m: number; new_level_m: number }
type Preview = {
  slug: string
  threshold_m: number
  max_abs_residual_m: number
  any_over: boolean
  items: ResRow[]
  diff: { added: number[]; removed: number[]; changed: Changed[] }
}
type StationRow = { slug: string; name: string; max_abs_residual_m: number; ok: boolean }

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const name = ref('')
const rows = ref<EditRow[]>([])
const savedRows = ref<EditRow[]>([])
const result = ref<Preview | null>(null)   // 最近一次试算结论
const committed = ref(false)               // 该结论是否已落库
const stale = ref(false)                   // 试算之后又改动过
const loading = ref(false)
const msg = ref('')
const err = ref('')
// 确认后与残差表 / 站表摘要的一致性核对
const consistency = ref<{ residuals: boolean; station: boolean } | null>(null)

const hasDiff = computed(() => {
  const d = result.value?.diff
  return !!d && (d.added.length + d.removed.length + d.changed.length > 0)
})

async function load() {
  loading.value = true
  err.value = ''
  try {
    const [st, res] = await Promise.all([
      getJSON<{ name: string }>(`/api/stations/${slug.value}`),
      getJSON<{ items: ResRow[] }>(`/api/stations/${slug.value}/residuals`),
    ])
    name.value = st.name
    rows.value = res.items.map((r) => ({ t_hours: r.t_hours, level_m: r.observed_m }))
    savedRows.value = cloneRows(rows.value)
    result.value = null
    committed.value = false
    stale.value = false
    consistency.value = null
  } catch (e) {
    err.value = String(e)
  } finally {
    loading.value = false
  }
}

function cloneRows(rs: EditRow[]): EditRow[] {
  return rs.map((r) => ({ t_hours: r.t_hours, level_m: r.level_m }))
}

function touch() {
  // 任何编辑都使上次试算/结论失效，必须重新试算才能确认
  stale.value = true
  committed.value = false
  consistency.value = null
  msg.value = ''
}

function addRow() {
  rows.value.push({ t_hours: '', level_m: '' })
  touch()
}

function removeRow(i: number) {
  rows.value.splice(i, 1)
  touch()
}

async function resetRows() {
  rows.value = cloneRows(savedRows.value)
  result.value = null
  committed.value = false
  stale.value = false
  consistency.value = null
  err.value = ''
  msg.value = ''
}

function payload() {
  return {
    observations: rows.value.map((r) => ({
      t_hours: typeof r.t_hours === 'number' ? r.t_hours : Number(r.t_hours),
      level_m: typeof r.level_m === 'number' ? r.level_m : Number(r.level_m),
    })),
  }
}

async function preview() {
  err.value = ''
  msg.value = ''
  try {
    result.value = await sendJSON<Preview>(
      `/api/stations/${slug.value}/observations/preview`, 'POST', payload())
    stale.value = false
    committed.value = false
    consistency.value = null
  } catch (e) {
    result.value = null
    err.value = '试算被拒：' + String(e).replace('Error: ', '')
  }
}

function sameResidual(a: Preview, b: { max_abs_residual_m: number; any_over: boolean; items: ResRow[] }) {
  return JSON.stringify({
    m: a.max_abs_residual_m,
    o: a.any_over,
    i: a.items,
  }) === JSON.stringify({ m: b.max_abs_residual_m, o: b.any_over, i: b.items })
}

async function commit() {
  if (!result.value || stale.value) return
  err.value = ''
  msg.value = ''
  try {
    const done = await sendJSON<Preview>(
      `/api/stations/${slug.value}/observations`, 'PUT', payload())
    // 落库后的结论必须等于确认前试算（残差逐点 / 最大绝对残差 / 超阈）
    if (!sameResidual(result.value, done)) {
      err.value = '落库结论与试算不一致，请刷新残差表核对'
      return
    }
    committed.value = true
    savedRows.value = cloneRows(rows.value)

    // 与残差表、站表摘要交叉核对
    const [res, stations] = await Promise.all([
      getJSON<Preview>(`/api/stations/${slug.value}/residuals`),
      getJSON<{ items: StationRow[] }>(`/api/stations`),
    ])
    const station = stations.items.find((s) => s.slug === slug.value)
    consistency.value = {
      residuals: sameResidual(result.value, res),
      station: !!station
        && station.max_abs_residual_m === result.value.max_abs_residual_m
        && station.ok === !result.value.any_over,
    }
    msg.value = '替换已落库'
  } catch (e) {
    err.value = '整批拒绝，库中仍是旧观测：' + String(e).replace('Error: ', '')
  }
}

onMounted(load)
</script>
<template>
  <div class="page">
    <h1>{{ name }} · 观测编辑</h1>
    <p class="lead">
      <RouterLink to="/stations">返回列表</RouterLink> ·
      <RouterLink :to="`/stations/${slug}`">分潮</RouterLink> ·
      <RouterLink :to="`/stations/${slug}/residuals`">残差表</RouterLink>
    </p>
    <p v-if="err" class="err">{{ err }}</p>
    <p v-if="msg" class="badge-ok">{{ msg }}</p>

    <div class="panel" style="margin-bottom: 14px">
      <table>
        <thead><tr><th style="width: 120px">时刻 t/h</th><th style="width: 180px">潮位 m</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(r, i) in rows" :key="i">
            <td><input type="number" step="any" v-model.number="r.t_hours" @input="touch" style="width: 110px" /></td>
            <td><input type="number" step="any" v-model.number="r.level_m" @input="touch" style="width: 160px" /></td>
            <td><button @click="removeRow(i)">删除</button></td>
          </tr>
        </tbody>
      </table>
      <div style="margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
        <button @click="addRow">新增一行</button>
        <button @click="preview" :disabled="loading">试算影响</button>
        <button @click="commit" :disabled="!result || stale || committed"
          style="background: #1a7a52">确认替换</button>
        <button @click="resetRows">放弃修改</button>
        <span v-if="stale" class="badge-bad">已改动，需重新试算</span>
      </div>
    </div>

    <div v-if="result" class="panel">
      <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
        <strong>{{ committed ? '替换后（已落库）' : '试算结果（尚未写入数据库）' }}</strong>
        <span :class="result.any_over ? 'badge-bad' : 'badge-ok'">
          {{ result.any_over ? '存在超阈点' : '全部未超阈' }}
        </span>
        <span class="lead">阈值 {{ result.threshold_m }} m · 最大 |残差| {{ result.max_abs_residual_m }} m ·
          {{ result.items.length }} 个观测点</span>
      </div>

      <div style="margin: 10px 0; display: flex; gap: 18px; flex-wrap: wrap">
        <span>新增时刻：<b :class="result.diff.added.length ? 'badge-ok' : 'lead'">
          [{{ result.diff.added.join(', ') || '无' }}]</b></span>
        <span>删除时刻：<b :class="result.diff.removed.length ? 'badge-bad' : 'lead'">
          [{{ result.diff.removed.join(', ') || '无' }}]</b></span>
        <span>潮位被改时刻：<b :class="result.diff.changed.length ? 'badge-bad' : 'lead'">
          [{{ result.diff.changed.map((c) => c.t_hours).join(', ') || '无' }}]</b></span>
      </div>
      <p v-if="!hasDiff" class="lead">与库中现有观测集合完全相同，无增删改。</p>

      <table v-if="result.diff.changed.length" style="margin-bottom: 12px">
        <thead><tr><th>改动 t/h</th><th>旧潮位</th><th>新潮位</th></tr></thead>
        <tbody>
          <tr v-for="(c, i) in result.diff.changed" :key="i">
            <td>{{ c.t_hours }}</td><td>{{ c.old_level_m }}</td><td>{{ c.new_level_m }}</td>
          </tr>
        </tbody>
      </table>

      <table>
        <thead><tr><th>t/h</th><th>实测</th><th>预报</th><th>残差</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(r, i) in result.items" :key="i">
            <td>{{ r.t_hours }}</td>
            <td>{{ r.observed_m }}</td>
            <td>{{ r.predicted_m }}</td>
            <td>{{ r.residual_m }}</td>
            <td :class="r.over ? 'badge-bad' : 'badge-ok'">{{ r.over ? '超限' : 'OK' }}</td>
          </tr>
        </tbody>
      </table>

      <p v-if="committed" class="badge-ok" style="margin-top: 10px">
        已确认写入，库中即为此观测集。
        <template v-if="consistency">
          残差表{{ consistency.residuals ? '与试算一致 ✓' : '与试算不一致 ✗' }}；
          站表摘要{{ consistency.station ? '与试算一致 ✓' : '与试算不一致 ✗' }}。
        </template>
        可前往<RouterLink :to="`/stations/${slug}/residuals`">残差表</RouterLink>或
        <RouterLink to="/stations">港口站列表</RouterLink>核对。
      </p>
      <p v-else class="lead" style="margin-top: 10px">
        以上仅为试算：此时打开
        <RouterLink :to="`/stations/${slug}/residuals`">残差表</RouterLink>
        看到的仍是旧观测。确认无误后点“确认替换”。
      </p>
    </div>
  </div>
</template>
