<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { getJSON } from '../api'

type Row = { t_hours: number; observed_m: number; predicted_m: number; residual_m: number; over: boolean }
const route = useRoute()
const thr = ref(0)
const maxAbs = ref(0)
const items = ref<Row[]>([])
const err = ref('')
onMounted(async () => {
  try {
    const slug = String(route.params.slug)
    const d = await getJSON<{ threshold_m: number; max_abs_residual_m: number; items: Row[] }>(`/api/stations/${slug}/residuals`)
    thr.value = d.threshold_m
    maxAbs.value = d.max_abs_residual_m
    items.value = d.items
  } catch (e) {
    err.value = String(e)
  }
})
</script>
<template>
  <div class="page">
    <h1>残差表</h1>
    <p class="lead">阈值 {{ thr }} m · 最大 |残差| {{ maxAbs }} m ·
      <RouterLink :to="`/stations/${route.params.slug}`">回分潮</RouterLink> ·
      <RouterLink :to="`/stations/${route.params.slug}/observations`">观测编辑</RouterLink></p>
    <p v-if="err" class="err">{{ err }}</p>
    <div class="panel">
      <table>
        <thead><tr><th>t/h</th><th>实测</th><th>预报</th><th>残差</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(r, i) in items" :key="i">
            <td>{{ r.t_hours }}</td>
            <td>{{ r.observed_m }}</td>
            <td>{{ r.predicted_m }}</td>
            <td>{{ r.residual_m }}</td>
            <td :class="r.over ? 'badge-bad' : 'badge-ok'">{{ r.over ? '超限' : 'OK' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
