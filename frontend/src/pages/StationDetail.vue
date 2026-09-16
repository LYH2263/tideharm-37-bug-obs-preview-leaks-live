<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { getJSON, sendJSON } from '../api'

type C = { name: string; speed_deg_per_hour: number; amplitude_m: number; phase_deg: number }
const route = useRoute()
const items = ref<C[]>([])
const name = ref('')
const msg = ref('')
const err = ref('')

async function load() {
  const slug = String(route.params.slug)
  const st = await getJSON<{ name: string; constituents: C[] }>(`/api/stations/${slug}`)
  name.value = st.name
  items.value = st.constituents
}

async function save() {
  err.value = ''
  msg.value = ''
  try {
    const slug = String(route.params.slug)
    await sendJSON(`/api/stations/${slug}/constituents`, 'PUT', { items: items.value })
    msg.value = '已保存'
    await load()
  } catch (e) {
    err.value = String(e)
  }
}

onMounted(() => load().catch((e) => (err.value = String(e))))
</script>
<template>
  <div class="page">
    <h1>{{ name }} · 分潮</h1>
    <p class="lead"><RouterLink to="/stations">返回列表</RouterLink> ·
      <RouterLink :to="`/stations/${route.params.slug}/residuals`">残差</RouterLink> ·
      <RouterLink :to="`/stations/${route.params.slug}/observations`">观测编辑</RouterLink></p>
    <p v-if="err" class="err">{{ err }}</p>
    <p v-if="msg">{{ msg }}</p>
    <div class="panel">
      <table>
        <thead><tr><th>名</th><th>角速度°/h</th><th>振幅 m</th><th>迟角 °</th></tr></thead>
        <tbody>
          <tr v-for="(c, i) in items" :key="i">
            <td><input v-model="c.name" /></td>
            <td><input type="number" step="0.001" v-model.number="c.speed_deg_per_hour" /></td>
            <td><input type="number" step="0.01" v-model.number="c.amplitude_m" /></td>
            <td><input type="number" step="0.1" v-model.number="c.phase_deg" /></td>
          </tr>
        </tbody>
      </table>
      <button style="margin-top: 12px" @click="save">保存分潮</button>
    </div>
  </div>
</template>
