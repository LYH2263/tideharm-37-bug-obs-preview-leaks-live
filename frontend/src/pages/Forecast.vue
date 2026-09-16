<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { getJSON } from '../api'

type Station = { slug: string; name: string; ok: boolean }
type Pt = { t_hours: number; level_m: number }

const stations = ref<Station[]>([])
const slug = ref('')
const points = ref<Pt[]>([])
const err = ref('')

async function loadStations() {
  const d = await getJSON<{ items: Station[] }>('/api/stations')
  stations.value = d.items
  if (!slug.value && d.items[0]) slug.value = d.items[0].slug
}

async function loadForecast() {
  if (!slug.value) return
  const d = await getJSON<{ points: Pt[] }>(`/api/stations/${slug.value}/forecast?hours=48&step_min=30`)
  points.value = d.points
}

onMounted(async () => {
  try {
    await loadStations()
    await loadForecast()
  } catch (e) {
    err.value = String(e)
  }
})
watch(slug, () => loadForecast().catch((e) => (err.value = String(e))))

const path = computed(() => {
  if (!points.value.length) return ''
  const w = 720, h = 280, pad = 30
  const xs = points.value.map((p) => p.t_hours)
  const ys = points.value.map((p) => p.level_m)
  const minX = Math.min(...xs), maxX = Math.max(...xs)
  const minY = Math.min(...ys), maxY = Math.max(...ys)
  const sx = (x: number) => pad + ((x - minX) / (maxX - minX || 1)) * (w - pad * 2)
  const sy = (y: number) => h - pad - ((y - minY) / (maxY - minY || 1)) * (h - pad * 2)
  return points.value.map((p, i) => `${i ? 'L' : 'M'}${sx(p.t_hours)},${sy(p.level_m)}`).join(' ')
})
</script>
<template>
  <div class="page">
    <h1>潮位台</h1>
    <p class="lead">对数不是电流：横轴时间、纵轴水位。选站看 48 小时合成预报。</p>
    <p v-if="err" class="err">{{ err }}</p>
    <div class="panel" style="margin-bottom: 12px">
      <label>港口站
        <select v-model="slug">
          <option v-for="s in stations" :key="s.slug" :value="s.slug">{{ s.name }}</option>
        </select>
      </label>
      <RouterLink v-if="slug" :to="`/stations/${slug}`" style="margin-left: 12px">分潮</RouterLink>
      <RouterLink v-if="slug" :to="`/stations/${slug}/residuals`" style="margin-left: 12px">残差</RouterLink>
      <RouterLink v-if="slug" :to="`/stations/${slug}/observations`" style="margin-left: 12px">观测</RouterLink>
    </div>
    <div class="panel plot">
      <svg viewBox="0 0 720 280" width="100%" height="280">
        <path :d="path" fill="none" stroke="#3dd6c6" stroke-width="2" />
      </svg>
    </div>
  </div>
</template>
