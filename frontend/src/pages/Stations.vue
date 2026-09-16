<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { getJSON } from '../api'

type Station = { slug: string; name: string; datum_m: number; max_abs_residual_m: number; ok: boolean }
const rows = ref<Station[]>([])
const err = ref('')
onMounted(async () => {
  try {
    rows.value = (await getJSON<{ items: Station[] }>('/api/stations')).items
  } catch (e) {
    err.value = String(e)
  }
})
</script>
<template>
  <div class="page">
    <h1>港口站</h1>
    <p v-if="err" class="err">{{ err }}</p>
    <div class="panel">
      <table>
        <thead><tr><th>站名</th><th>基面</th><th>最大残差</th><th>状态</th><th></th></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.slug">
            <td>{{ r.name }}</td>
            <td>{{ r.datum_m }} m</td>
            <td>{{ r.max_abs_residual_m }} m</td>
            <td :class="r.ok ? 'badge-ok' : 'badge-bad'">{{ r.ok ? '残差合格' : '残差偏大' }}</td>
            <td>
              <RouterLink :to="`/stations/${r.slug}`">分潮</RouterLink> ·
              <RouterLink :to="`/stations/${r.slug}/observations`">观测</RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
